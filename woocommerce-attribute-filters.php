<?php 
/*
Plugin Name: WooCommerce Attribute Filters
Plugin URI: 
Description: A widget that automatically adds a filter for all available WooCommerce attributes found in the current category.
Version: 0.1
Author: AScottMcCauley
Author URI: http://ascottmccauley.com
*/

//TODO: Setup product categories (possibly subcategories) to be filterable if not on a category page
//TODO: Add a Clear all button at the top, after filters have been chosen

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Adds widget.
 */
class WooCommerce_Attribute_Filters_Widget extends WP_Widget {

	/**
	 * Register widget with WordPress.
	 */
	function __construct() {
		parent::__construct(
			'attribute_filters', // Base ID
			__( 'Attribute Filters', 'woocommerce_attribute_filters' ), // Name
			array( 'description' => __( 'Lists all attributes of currently shown products to be filtered out as needed', 'woocommerce_attribute_filters' ), ) // Args
		);
	}

	/**
	 * Front-end display of widget.
	 *
	 * @see WP_Widget::widget()
	 *
	 * @param array $args     Widget arguments.
	 * @param array $instance Saved values from database.
	 */
	public function widget( $args, $instance ) {
		global $_chosen_attributes;
		global $wp_query;
		
		// Only show on pages that show products
		if ( ! is_post_type_archive( 'product' ) && ! is_tax( get_object_taxonomies( 'product' ) ) && ! is_search() ) {
			return;
		}
		
		echo $args['before_widget'];
		
		if ( ! empty( $instance['title'] ) ) {
			echo $args['before_title'] . apply_filters( 'widget_title', $instance['title'] ). $args['after_title'];
		}
		
		$query_type =  ! empty( $instance['query_type'] ) ?  $instance['query_type'] : "AND";
		
		// If current page is an attribute or taxonomy page, get the term to skip when filtering
		$current_term = is_tax() ? get_queried_object()->term_id : '';
		$current_tax  = is_tax() ? get_queried_object()->taxonomy : '';
		
		// Loop through all global attributes and get a count of how many are present in the current query
		if ( function_exists( 'wc_get_attribute_taxonomies' ) ) {
			foreach ( wc_get_attribute_taxonomies() as $taxonomy ) {
				$tax = 'pa_' . $taxonomy->attribute_name;
				$get_terms_args = array( 'hide_empty' => '1' );
				$orderby = wc_attribute_orderby( $tax );
				
				switch ( $orderby ) {
					case 'name' :
						$get_terms_args['orderby']    = 'name';
						$get_terms_args['menu_order'] = false;
					break;
					case 'id' :
						$get_terms_args['orderby']    = 'id';
						$get_terms_args['order']      = 'ASC';
						$get_terms_args['menu_order'] = false;
					break;
					case 'menu_order' :
						$get_terms_args['menu_order'] = 'ASC';
					break;
				}
				
				$terms = get_terms( $tax, $get_terms_args );
				if ( count( $terms ) > 0 ) {
					ob_start();
					
					echo '<dl>';
					echo '<dt><h5>' . $taxonomy->attribute_label . '</h5></dt>';
					
					$found = false;
					// Force found when option is selected - do not force found on taxonomy attributes
					if ( ! is_tax() && is_array( $_chosen_attributes ) && array_key_exists( $tax, $_chosen_attributes ) ) {
						$found = true;
					}
					
					foreach ( $terms as $term ) {
						// skip the term for the current archive
						if ( $current_term == $term->term_id ) {
							continue;
						}
						
						// Get count based on current view - uses transients
						$transient_name = 'wc_ln_count_' . md5( sanitize_key($tax) . sanitize_key( $term->term_taxonomy_id ) );
						if ( false === $_products_in_term = ( get_transient( $transient_name ) ) ) {
							$_products_in_term = get_objects_in_term( $term->term_id, $tax);
							set_transient( $transient_name, $_products_in_term );
						}
						
						// Automatically include the attribute if it is selected
						$option_is_set = ( isset( $_chosen_attributes[$tax] ) && in_array( $term->term_id, $_chosen_attributes[$tax]['terms'] ) );
						
						if( $option_is_set ) {
							$found = true;
						}
						
						if ( is_search() ) {
							$current_wp_query = $wp_query->query;
							$meta_query = array(
								'key'     => '_visibility',
						    'value'   => array( 'visible', 'search' ),
						    'compare' => 'IN',
							);
							$query_ids = get_posts(
								array_merge(
									$current_wp_query,
									array(
										'post_type'              => 'product',
										'numberposts'            => -1,
										'post_status'            => 'publish',
										'meta_query'             => $meta_query,
										'fields'                 => 'ids',
										'no_found_rows'          => true,
										'update_post_meta_cache' => false,
										'update_post_term_cache' => false,
										'pagename'               => '',
										'wc_query'               => 'get_products_in_view'
									)
								)
							);
							if ( $query_type == "OR" ) {
								// If the $query_type is "OR", show all options so that the search can be expanded
								$count = sizeof( array_intersect( $_products_in_term, $query_ids ) );
								if ( $count > 0 ) {
									$found = true;
								}
							} else {
								$count = sizeof( array_intersect( $_products_in_term, $query_ids ) );
								if ( $count > 0 && $current_term !== $term->term_id ) {
									$found = true;
								}
							}
						} else {
							if ( $query_type == "OR" ) {
								// If the $query_type is "OR", show all options so that the search can be expanded
								$count = sizeof( array_intersect( $_products_in_term, WC()->query->unfiltered_product_ids ) );
								if ( $count > 0 ) {
									$found = true;
								}
							} else {
								$count = sizeof( array_intersect( $_products_in_term, WC()->query->filtered_product_ids ) );
								if ( $count > 0 && $current_term !== $term->term_id ) {
									$found = true;
								}
							}
						}
	
						if ( 0 == $count && !$option_is_set ) {
							continue;
						}
		
						$arg = 'filter_' . sanitize_title( $taxonomy->attribute_label );
						$current_filter = ( isset( $_GET[ $arg ] ) ) ? explode( ',', $_GET[ $arg ] ) : array();
		
						if ( ! is_array( $current_filter ) ) {
							$current_filter = array();
						}
		
						$current_filter = array_map( 'esc_attr', $current_filter );
		
						if ( ! in_array( $term->term_id, $current_filter ) ) {
							$current_filter[] = $term->term_id;
						}
						
						// Base Link decided by current page
						if ( defined( 'SHOP_IS_ON_FRONT' ) ) {
							$link = home_url();
						} elseif ( is_post_type_archive( 'product' ) || is_page( wc_get_page_id('shop') ) ) {
							$link = get_post_type_archive_link( 'product' );
						} elseif ( is_search() ) {
							$link = get_home_url() . '/search/' . $wp_query->s;
						} else {
							$link = get_term_link( get_query_var( 'term' ), get_query_var( 'taxonomy' ) );
						}
		
						// All current filters
						if ( isset( $_chosen_attributes ) ) {
							foreach ( $_chosen_attributes as $name => $data ) {
								if ( $name !== $tax) {
		
									// Exclude query arg for current term archive term
									while ( in_array( $current_term, $data['terms'] ) ) {
										$key = array_search( $current_term, $data );
										unset( $data['terms'][$key] );
									}
		
									// Remove pa_ and sanitize
									$filter_name = sanitize_title( str_replace( 'pa_', '', $name ) );
		
									if ( ! empty( $data['terms'] ) ) {
										$link = add_query_arg( 'filter_' . $filter_name, implode( ',', $data['terms'] ), $link );
									}
									
								}
							}
						}
		
						// Min/Max
						if ( isset( $_GET['min_price'] ) ) {
							$link = add_query_arg( 'min_price', $_GET['min_price'], $link );
						}
		
						if ( isset( $_GET['max_price'] ) ) {
							$link = add_query_arg( 'max_price', $_GET['max_price'], $link );
						}
		
						// Orderby
						if ( isset( $_GET['orderby'] ) ) {
							$link = add_query_arg( 'orderby', $_GET['orderby'], $link );
						}
		
						// Add Query Arg
						if ( isset( $_chosen_attributes[ $tax] ) && is_array( $_chosen_attributes[ $tax]['terms'] ) && in_array( $term->term_id, $_chosen_attributes[ $tax]['terms'] ) ) {
		
							$class = 'class="filtered"';
		
							// Remove this term if $current_filter has more than 1 term filtered
							if ( sizeof( $current_filter ) > 1 ) {
								$current_filter_without_this = array_diff( $current_filter, array( $term->term_id ) );
								$link = add_query_arg( $arg, implode( ',', $current_filter_without_this ), $link );
							}
		
						} else {
		
							$class = '';
							$link = add_query_arg( $arg, implode( ',', $current_filter ), $link );
		
						}
		
						// Search Arg
						if ( get_search_query() ) {
							$link = add_query_arg( 's', get_search_query(), $link );
						}
		
						// Post Type Arg
						if ( isset( $_GET['post_type'] ) ) {
							$link = add_query_arg( 'post_type', $_GET['post_type'], $link );
						}
						
						echo '<dd ' . $class . '>';
						
						echo ( $count > 0 || $option_is_set ) ? '<a href="' . esc_url( apply_filters( 'woocommerce_layered_nav_link', $link ) ) . '">' : '<span>';
		
						echo $term->name;
						
						echo ( $count > 0 || $option_is_set ) ? ' <span class="counts">( ' . $count . ' )</span></a>' : '</span>';
		
						echo '</dd>';
						
					}//end foreach
					
					echo '</dl>';
					
					// Output the contents
					if ( ! $found ) {
						ob_end_clean();
					} else {
						echo ob_get_clean();
					}
				}
			}
			echo $args['after_widget'];
		}
	}

	/**
	 * Back-end widget form.
	 *
	 * @see WP_Widget::form()
	 *
	 * @param array $instance Previously saved values from database.
	 */
	public function form( $instance ) {
		$title = ! empty( $instance['title'] ) ? $instance['title'] : __( 'New title', 'woocommerce_attribute_filters' );
		?>
		<p>
			<label for="<?php echo $this->get_field_id( 'title' ); ?>"><?php _e( 'Title:' ); ?></label> 
			<input class="widefat" id="<?php echo $this->get_field_id( 'title' ); ?>" name="<?php echo $this->get_field_name( 'title' ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>">
		</p>
		<?php 
	}

	/**
	 * Sanitize widget form values as they are saved.
	 *
	 * @see WP_Widget::update()
	 *
	 * @param array $new_instance Values just sent to be saved.
	 * @param array $old_instance Previously saved values from database.
	 *
	 * @return array Updated safe values to be saved.
	 */
	public function update( $new_instance, $old_instance ) {		
		$instance = array();
		$instance['title'] = ( ! empty( $new_instance['title'] ) ) ? strip_tags( $new_instance['title'] ) : '';
		
		return $instance;
	}

}

function woocommerce_attribute_filter_register_widget() {
	register_widget( 'WooCommerce_Attribute_Filters_Widget' );
}
add_action( 'widgets_init', 'woocommerce_attribute_filter_register_widget' );

// Recreate WooCommerce's standard filtering method 
function woo_attribute_filters_init() {
	global $_chosen_attributes;
	$_chosen_attributes = array();
	if ( function_exists( 'wc_get_attribute_taxonomies' ) ) {
	$attribute_taxonomies = wc_get_attribute_taxonomies();
		if ( $attribute_taxonomies ) {
			foreach ( $attribute_taxonomies as $tax ) {
				$attribute = wc_sanitize_taxonomy_name( $tax->attribute_name );
				$taxonomy = wc_attribute_taxonomy_name( $attribute );
				$name = 'filter_' . $attribute;
				if ( ! empty( $_GET[$name] ) && taxonomy_exists( $taxonomy ) ) {
					$_chosen_attributes[$taxonomy]['terms'] = explode( ',', $_GET[$name] );
					$_chosen_attributes[$taxonomy]['query_type'] = 'AND';
				}
			}
		}
		add_filter( 'loop_shop_post_in', 'woo_attribute_filters_query' );
	}
}
add_action( 'init', 'woo_attribute_filters_init' );

// Recreate WooCommerce's layered_nav_query, and allow for different filtering methods
// Called when the `loop_shop_post_in` filter is applied in WooCommerce
function woo_attribute_filters_query( $filtered_posts ) {
	global $_chosen_attributes;
	global $_filter_method;
	
	if ( sizeof( $_chosen_attributes ) > 0 ) {
		$tax_queries = array();
		
		foreach ( $_chosen_attributes as $attribute => $data ) {
			if ( sizeof($data['terms'] ) > 0) {
				$tax_query = array(
					'taxonomy'=> $attribute,
					'terms' => $data['terms'],
					'field' => 'term_id',
					'operator' => "AND",
				);
				array_push( $tax_queries, $tax_query );
			}
		}
		
		if( $tax_queries ) {
			$posts_to_filter = get_posts(
				array(
					'post_type' => 'product',
					'numberposts' => -1,
					'post_status' => 'publish',
					'fields' => 'ids',
					'no_found_rows' => true,
					'tax_query' => $tax_queries,
				)
			);
			
			if ( sizeof($filtered_posts ) == 0) {
				$filtered_posts = $posts_to_filter;
			} else {
				$filtered_posts = array_merge( $filtered_posts, $posts_to_filter );
			}
		}
	}
	return (array) $filtered_posts;
}
