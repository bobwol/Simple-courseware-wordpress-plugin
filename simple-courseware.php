<?php
/*
 * Plugin Name: Simple courseware
 * Description: Split your wordpress site into several course web pages
 * Version: 0.0
 * Author: Samuel Coskey
 * Author URI: http://boolesrings.org
*/

/*
 *
 * TAXONOMY CODE
 *
 ****************/

/*
 * add the "Course" taxonomy -- works like a category
*/
add_action( 'init', 'create_course_taxonomy', 0 );
function create_course_taxonomy() {
	$labels = array(
		'name' => _x( 'Courses', 'taxonomy general name' ),
		'singular_name' => _x( 'Course', 'taxonomy singular name' ),
		'search_items' =>  __( 'Search Courses' ),
		'popular_items' => __( 'Most Used Courses' ),
		'all_items' => __( 'All Courses' ),
		'parent_item' => null,
		'parent_item_colon' => null,
		'edit_item' => __( 'Edit Course' ), 
		'update_item' => __( 'Update Course' ),
		'add_new_item' => __( 'Add New Course' ),
		'new_item_name' => __( 'New Course Name' ),
		'separate_items_with_commas' => null,
		'add_or_remove_items' => __( 'Add or remove courses' ),
		'choose_from_most_used' => __( 'Choose from the most used courses' ),
		'menu_name' => __( 'Courses' ),
	);
	register_taxonomy('course', array('post','page'), array(
		'public' => true,
		'labels' => $labels,
		'hierarchical' => true, // we don't use this though
	));
}


/*
 * prevent posts with a course taxonomy from appearing on the main page
 * to do: make it less silly/inefficient
 * to do: figure out archive pages?
*/
add_filter('pre_get_posts','courseware_exclude_course_posts');
function courseware_exclude_course_posts( $query ) {
	if ( $query->is_home || ($query->is_feed && !$query->is_tax) ) {
		$query->set('tax_query', array(
			array(
				'taxonomy' => 'course',
				'field' => 'id',
				'terms' => get_terms('course','fields=ids'),
				'operator' => 'NOT IN',
			)
		));
	}
	return $query;
}

/*
 * add the "Course" column to the page and posts management areas
 * add a dropdown to filter based on the course taxonomy
*/
add_filter( 'manage_post_posts_columns', 'courseware_add_course_column' );
add_filter( 'manage_page_posts_columns', 'courseware_add_course_column' );
function courseware_add_course_column( $columns ) {
	$offset = 3;
	$columns = array_slice( $columns, 0, $offset,true )
	  + array( 'course' => "Course" )
	  + array_slice( $columns, $offset, null, true);
	return $columns;
}

add_action( 'manage_post_posts_custom_column', 'courseware_display_course_column', 10, 2 );
add_action( 'manage_page_posts_custom_column', 'courseware_display_course_column', 10, 2 );
function display_course_column( $column, $post_id ) {
	if ( $column == "course" ) {
		echo get_the_term_list( $post_id, 'course', '', ',', '' );
	}
}

add_action( 'restrict_manage_posts', 'add_course_filter' );
function add_course_filter() {
	global $typenow;
	if ( $typenow != 'post' ) {
		return;
	}
	$current_course = $_GET['filter_course'] ? $_GET['filter_course'] : -1;
	wp_dropdown_categories('taxonomy=course&hide_empty=true&name=filter_course&orderby=name&show_option_all=View all courses&selected='.$current_course);
}

add_action('load-edit.php', 'add_my_query_filter');
function add_my_query_filter() {
	add_filter('parse_query','filter_manage_courses');
}
function filter_manage_courses( $q ) {
	$filter_course_id = $_GET['filter_course'];
	$filter_course = ( $filter_course_id > 0 ) ? get_term( $filter_course_id, 'course' )->slug : null;
	if ( $filter_course ) {
		$q->set( 'course', $filter_course );
	}
}


/*
 * make a custom box in the page/post editor for choosing the Course
 * this is needed because the default box lets you select multiple courses
*/
add_action('admin_menu', 'courseware_add_course_selector');
function courseware_add_course_selector() {
	remove_meta_box('coursediv', 'post', 'side');
	remove_meta_box('coursediv', 'page', 'side');
	add_meta_box('course_box_ID', __('Course'), 'courseware_selector_generator', 'post', 'side');
	add_meta_box('course_page_box_ID', __('Course'), 'courseware_selector_generator', 'page', 'side');
}
function courseware_selector_generator( $post ) {
	$terms = wp_get_object_terms( $post->ID, 'course', 'fields=ids' );
	wp_nonce_field( plugin_basename( __FILE__ ), 'courses_noncename' );
	wp_dropdown_categories('taxonomy=course&hide_empty=0&name=post_course&orderby=name&show_option_none=None&selected='.$terms[0]);
}

add_action( 'save_post', 'courseware_save_course' );
function courseware_save_course( $post_id ) {
	// verify if this is an auto save routine.
	// if so our form has not been submitted, so we dont want to do anything
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	// verify this came from our screen and with proper authorization,
	// because save_post can be triggered at other times
	if ( !wp_verify_nonce( $_POST['courses_noncename'], plugin_basename( __FILE__ ) ) ) {
		return;
	}
	// Check permissions
	if ( !current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	// OK, we're authenticated: we need to find and save the data
	$new_course_id = $_POST['post_course'];
	$new_course = ( $new_course_id > 0 ) ? get_term( $new_course_id, 'course' )->slug : null;
	wp_set_object_terms(  $post_id , $new_course, 'course' );
}


/*
 * create a special sidebar to be used on all course pages
*/
add_action( 'widgets_init', 'courseware_add_custom_sidebar', 100 );
function courseware_add_custom_sidebar() {
	register_sidebar(array(
		'name' => __( 'Course Pages Sidebar' ),
		'id' => 'courseware-sidebar',
		'description' => __( 'Widgets in this area will be shown on all course pages.' ),
	));
}


/*
 *
 * SWITCHEROO CODE
 *
 *******************/

add_action( 'wp_head', 'courseware_switcheroo' );
function courseware_switcheroo() {
	// logic to determine when we are on a course post, page, or archive.
	// set the $course variable with the relevant term object.
	if ( is_page() ) {
		global $post;
		// check if we are even a descendant of a course page
		while ( $post->post_parent ) {
			$post = get_post( $post->post_parent );
		}
		$terms = wp_get_object_terms( $post->ID, 'course' );
		if ( !$terms || is_wp_error($terms) ) {
			return;
		}
		$course = $terms[0];
	} elseif ( is_single() ) {
		global $post;
		$terms = wp_get_object_terms( $post->ID, 'course' );
		if ( !$terms || is_wp_error($terms) ) {
			return;
		}
		$course = $terms[0];
	} elseif ( is_archive() ) {
		global $wp_query;
		$term_slug = $wp_query->get( 'course' );
		if ( !$term_slug ) {
			return;
		}
		$course = get_term_by( 'slug', $term_slug, 'course' );
	} else {
		return;
	}	

	// swap out the blog name
	add_filter( 'option_blogname', function()use($course){
		return $course->name;
	} );

	// swap out the blog description
	add_filter( 'option_blogdescription', function()use($course){
		return $course->description;
	} );

	// swap out the sidebar
	// to do: make compatible with other themes
	// copied from: http://wordpress.org/extend/plugins/custom-sidebars/
	global $wp_registered_sidebars, $_wp_sidebars_widgets;
	$_wp_sidebars_widgets["primary-widget-area"] = $_wp_sidebars_widgets["courseware-sidebar"];

	// locate the main course page
	$toplevel = get_posts( "course=" . $course->slug . "&post_type=page&post_parent=0&numberposts=1" );
	if ( !$toplevel ) {
		return;
	}
	$mainpage = $toplevel[0];  // assume there is only one... otherwise most recent?

	// swap out the blog url
	// to do: make compatible with more themes and permalink structures
	add_filter( 'home_url', function($u,$path,$o,$b)use($mainpage){
		if ( $path == "/" ) {
			return $u . $mainpage->page_name;
		} else {
			return $u;
		}
	}, 10, 4 );

	// swap out the menu
	add_filter( 'wp_nav_menu_items', function()use($mainpage){
		return "<li><a href='" . get_option('siteurl') . "'>&larr;</a></li>"
		  . wp_list_pages( "echo=0&title_li=&include=" . $mainpage->ID )
		  . wp_list_pages( "echo=0&title_li=&child_of=" . $mainpage->ID );
	} );

	// swap out the header image on posts and pages
	$feature_image_id = get_post_thumbnail_id( $mainpage->ID );
	add_filter( 'get_post_metadata', function($j,$id,$k) use($feature_image_id) {
		if ( $k == "_thumbnail_id" ) {
			return $feature_image_id;
		}
	}, 10, 3);

	// swap out the header image on archive pages
	add_filter( 'theme_mod_header_image', function($u) use($feature_image_id) {
		if ( $feature_image_id ) {
			$src = wp_get_attachment_image_src( $feature_image_id, "full" );
			return $src[0];
		} else {
			return $u;
		}
	});
}

/*
 *
 * SHORT CODE
 *
 **************/

?>
