<?php

/*

Plugin Name: Flickr Post
Version: 0.1
Plugin URI: http://mcnicks.org/software/flickr-post/
Description: Automatically includes specially tagged Flickr photographs in WordPress posts. For a photo to appear, it must be tagged with the word 'wordpress' and with the post slug of the post.
Author: David McNicol
Author URI: http://mcnicks.org/

Copyright (c) 2005
Released under the GPL license
http://www.gnu.org/licenses/gpl.txt

This file is part of WordPress.
WordPress is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA

*/



/* Load the config file. */

include_once( ABSPATH . "wp-content/plugins/flickr-post.conf" );



/*** Constants - do not change any of these. ***/



/* Used to find the user_id that matches the given username. */

define( 'FP_PHOTO_URL', 'http://flickr.com/photos/' );

/* Used to issue REST requests against the Flickr web site. */

define( 'FP_REST_URI', 'http://flickr.com/services/rest/' );

/* Flickr API key. */ 

define( 'FP_API_KEY', '1244ae1e42c346543747aaec524cbe7a' );

/* NOTE: do not change this, and do not reuse it. If you need an
   API key to build a WordPress plugin, you can have one assigned
   by following the instructions at:
     http://flickr.com/services/api/misc.api_keys.html */

/* Debug mode - only set this to 1 if you need to debug. */

define( 'FP_DEBUG', 0 );



/*** Functions - do not change any of these either. ***/



/* fp_add_photos
    - $content contains the existing content of the post
    - returns the existing content prefixed with photos

   This is the filter that adds the photos to the top of the content
   of the post. The call to the add_action function tells WordPress
   to call the function last (since a priority of '0' is specified). */

add_action( 'the_content', 'fp_add_photos', 0 );
add_action( 'the_excerpt', 'fp_add_photos', 0 );

function fp_add_photos ( $content ) {

  // Get the user ID.

  $user_id = fp_get_user_id();

  // Get the slug of the current post.

  $slug = get_the_slug();
  
  // Get the photos.

  list( $ids, $uris, $titles ) = fp_get_photos( $user_id, $slug );

  if ( ! $ids ) return $content;

  // Construct the HTML.

  $fp_photos = '<div class="flickr-post">';

  foreach ( $ids as $id ) {

    $uri = $uris[$id];
    $title = $titles[$id];
    $fp_photos .= '<a href="'.$uri.'_o.jpg" rel="bookmark" title="'.$title.'">';
    $fp_photos .= '<img src="'.$uri.'_s.jpg" alt="'.$title.'"/>';
    $fp_photos .= '</a>';
  }

  $fp_photos .= '</div>';

  return $fp_photos . $content;
}



/* fp_get_user_id
    - returns the user ID associated with the predefined username

   This looks up the given username using a Flickr REST request. The
   results are cached so avoid excessive calls to Flickr. */

function fp_get_user_id () {

  // See if the value has been cached.

  if ( $user_id = fp_get_cached_value( "user_id" ) ) return $user_id;
    
  // Otherwise, make a REST request for the user ID.

  $response = fp_rest_request( "flickr.urls.lookupUser",
   "url=".FP_PHOTO_URL.FP_USERNAME, "username" );

  // Look up the user ID, cache it for later and return it

  $user_id = $response['1']['attributes']['id'];

  if ( $user_id ) {

    fp_cache_value( "user_id", $user_id );
    return $user_id;
  }
}



/* fp_get_photos
    - $user_id is the ID of the user whose Flickr album we are displaying
    - $slug is the slug of the current post
    - returns two values in an array: an array of photo titles and an array
      of partial photo URIs

   This function returns the front section of the URI for each photo
   that matches the given user_id and slug. The partial URI can be suffixed
   with the appropriate ending to refer to the actual thumbnail or full
   photo. */

function fp_get_photos ( $user_id, $slug ) {
  global $id;

  // Return if the user_id and slug are not specified.

  if ( ! $user_id || ! $slug ) return;

  // Make a REST request for the photos.

  $clean_slug = preg_replace( "/[^a-z\d]/", "", $slug ); 

  $response = fp_rest_request( "flickr.photos.search",
   "user_id=$user_id&tags=wp$clean_slug".",wp$id", $id );

  $ids = array();
  $titles = array();
  $urls = array();

  foreach ( $response as $order => $tag ) {
    if ( $tag['tag'] == 'photo' ) {

      $ph_server = $tag['attributes']['server'];
      $ph_id = $tag['attributes']['id'];
      $ph_secret = $tag['attributes']['secret'];
      $ph_ispublic = $tag['attributes']['ispublic'];
      $ph_server = $tag['attributes']['server'];
      $ph_title = $tag['attributes']['title'];
    
      if ( $ph_ispublic ) {

        array_unshift( $ids, $ph_id );

        $urls[$ph_id] = "http://photos" . $ph_server . ".flickr.com/"
         . $ph_id . "_" . $ph_secret;

        $titles[$ph_id] = $ph_title;
      }
    }
  }

  return array( $ids, $urls, $titles );
}



/* fp_rest_request
    - $method is the REST method which should be called
    - $args specified the arguments that should be sent
    - (optional) $slug a slug used to cache results locally
    - returns an XML document containing the response

   This function issues a REST request based on the specified method
   and arguments. It adds mandatory REST arguments to those specified,
   converts the REST response it receives into an XML document and
   returns it. If a cache slug is given, the last request matching
   the method and slug will be returned if it is fresh enough. */

function fp_rest_request ( $method = "", $args = "", $slug = "" ) {

  // Make sure we have the correct arguments.

  if ( ! $method || ! $args ) return;

  // Check if we should do caching.

  if ( $slug ) {
    $response = fp_get_cached_response( $method, $slug );
  }

  // If we did not get a cached response, issue the REST request.

  if ( ! $response ) {

    // Issue the request and collect the response.

    $uri = FP_REST_URI."?method=$method&api_key=".FP_API_KEY."&$args";

    $handle = @fopen( $uri, "r" );

    if ( $handle ) {

      while ( $part = @fread( $handle, 8192 ) ) {
        $response .= $part;
      }

      @fclose( $handle );

      // Cache the new response if we have a slug.

      if ( $slug ) fp_cache_response( $method, $slug, $response );
    }
  }

  // Return the response in an XML tree.

  return fp_make_xml_tree( $response );
}



/* fp_get_cached_response
    - $method is the REST method to be cached
    - $slug is used to make the cached response more unique

   This function uses $method and $cache to make up a unique filename
   which is used to store REST responses. If the file associated with
   $request and $slug exists, its contents are returned. If the file
   is stale, then nothing is returned, which should prompt a refresh. */

function fp_get_cached_response( $method, $slug ) {

  // Return if the method and slug are not specified.

  if ( ! $method || ! $slug ) return;
  // Work out the file name that the response should be cached in.

  $filename = FP_CACHE_DIR."$method--$slug";

  // Return if the file does not exist.

  if ( ! @file_exists( $filename ) ) return;

  // Return if the file is stale.

  if ( ( @filemtime( $filename ) + FP_CACHE_TIMEOUT ) < ( time() ) ) return;

  // Otherwise, open it and return the contents.

  $handle = @fopen( $filename, "r" );

  if ( $handle ) {

    $cached_response = "";

    while ( $part = @fread( $handle, 8192 ) ) {
      $cached_response .= $part;
    }

    @fclose( $handle );

    return $cached_response;
  }
}



/* fp_cache_response
    - $method is the REST method to be cached
    - $slug is used to make the cached response more unique
    - $response is the actual response that we should cache

   This function uses $method and $cache to make up a unique filename
   which is used to store REST responses. When called, the function
   writes the given response to that filename. */

function fp_cache_response( $method, $slug, $response ) {

  // Return if the arguments are not specified.

  if ( ! $method || ! $slug  || ! $response ) return;

  // Work out the file name that the response should be cached in.

  $filename = FP_CACHE_DIR."$method--$slug";

  // Open it for writing and dump the response in.

  $handle = @fopen( $filename, "w+" );

  if ( $handle ) {

    @fwrite( $handle, $response );
    @fclose( $handle );
  }

  // That almost seemed too simple.
}



/* fp_cache_value
    - $name the name of the value to cache
    - $value the value itself

   This function uses the standard caching functions above to cache
   name/value pairs. At the moment this is a bit of a fudge, making
   use of the special method, "value". */

function fp_cache_value( $name, $value ) {
  return fp_cache_response( "value", $name, $value );
}



/* fp_get_cached_value
    - $name the name of the value to cache
    - returns the cached value associated with $name

   This function uses the standard caching functions above to return
   a previously cached value associated with a name/value pair. At the
   moment this is a bit of a fudge, making use of the special method,
   "value". */

function fp_get_cached_value( $name ) {
  return fp_get_cached_response( "value", $name );
}



/* fp_make_xml_tree
    - $xml is a string containing XML code
    - returns a multi-dimensional data structure containing the XML

   Note: I borrowed this code from Ramon Darrow's Flickr Gallery plugin,
   which is available at:

     http://www.worrad.com/archives/2004/11/30/flickr-gallery-wp-plugin/

   This function takes a given string which contains XML and parses
   it, returning a structure of associative arrays that contain the
   various XML tags, attributes, values and content. */

function fp_make_xml_tree( $xml ) {

  // Return if no XML was specified.

  if ( ! $xml ) return;

  // Do the parsing.

  $output = array();

  $parser = xml_parser_create();

  xml_parser_set_option($parser, XML_OPTION_CASE_FOLDING, 0);
  xml_parser_set_option($parser, XML_OPTION_SKIP_WHITE, 1);
  xml_parse_into_struct($parser, $xml, $values, $tags);
  xml_parser_free($parser);

  if ( FP_DEBUG ) {
    print "<pre>";
    print_r( $values );
    print "</pre>";
  }

  return $values;
}



/* get_the_slug
    - returns the slug associated with the current post

   This is borrowed from the code for get_the_title in:
     wp-includes/template-functions-post.php
*/

function get_the_slug () {
  global $id, $wpdb;

  if ( $id > 0 )
    return $wpdb->get_var("SELECT post_name FROM $wpdb->posts WHERE ID = $id");
}

?>
