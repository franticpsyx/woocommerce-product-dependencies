<?php

/*
* Plugin Name: WooCommerce Product Dependencies
* Plugin URI: http://somewherewarm.gr/
* Description: Restrict access to WooCommerce products, depending on the ownership and/or purchase of other, required products.
* Version: 1.1.3
* Author: SomewhereWarm
* Author URI: https://somewherewarm.gr/
*
* Text Domain: woocommerce-product-dependencies
* Domain Path: /languages/
*
* Requires at least: 3.8
* Tested up to: 4.9
*
* WC requires at least: 3.0
* WC tested up to: 3.2
*
* License: GNU General Public License v3.0
* License URI: http://www.gnu.org/licenses/gpl-3.0.html
*/

class WC_Product_Dependencies {

	/**
	 * The single instance of the class.
	 * @var WC_Product_Dependencies
	 *
	 * @since 1.1.0
	 */
	protected static $_instance = null;

	/**
	 * Required WC version.
	 * @var string
	 */
	private $required = '2.2';

	/**
	 * Product Dependencies version.
	 */
	public $version = '1.1.3';

	/**
	 * Main WC_Product_Dependencies instance.
	 *
	 * Ensures only one instance of WC_Product_Dependencies is loaded or can be loaded - @see 'WC_Product_Dependencies()'.
	 *
	 * @since  1.1.0
	 *
	 * @static
	 * @return WC_Product_Dependencies - Main instance
	 */
	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * Cloning is forbidden.
	 *
	 * @since 1.1.0
	 */
	public function __clone() {
		_doing_it_wrong( __FUNCTION__, __( 'Foul!', 'woocommerce-product-dependencies' ), '1.1.0' );
	}

	/**
	 * Unserializing instances of this class is forbidden.
	 *
	 * @since 1.1.0
	 */
	public function __wakeup() {
		_doing_it_wrong( __FUNCTION__, __( 'Foul!', 'woocommerce-product-dependencies' ), '1.1.0' );
	}

	/**
	 * Fire in the hole!
	 */
	public function __construct() {

		// Load plugin on 'plugins_loaded' hook.
		add_action( 'plugins_loaded', array( $this, 'plugins_loaded' ) );
	}

	/**
	 * The plugin url
	 * @return string
	 */
	public function plugin_url() {
		return plugins_url( basename( plugin_dir_path( __FILE__ ) ), basename(__FILE__) );
	}

	/**
	 * Initialize.
	 *
	 * @return void
	 */
	public function plugins_loaded() {

		// Core compatibility functions.
		require_once( 'class-wc-pd-core-compatibility.php' );

		if ( ! function_exists( 'WC' ) || ! WC_PD_Core_Compatibility::is_wc_version_gte_2_2() ) {
			return;
		}

		// Helper functions.
		require_once( 'class-wc-pd-helpers.php' );

		// Init textdomain.
		add_action( 'init', array( $this, 'init') );

		// Validate add-to-cart action.
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'add_to_cart_validation' ), 10, 3 );

		// Validate products in cart.
		add_action( 'woocommerce_check_cart_items', array( $this, 'check_cart_items' ), 1 );

		if ( is_admin() ) {

			// Admin jQuery.
			add_action( 'admin_enqueue_scripts', array( $this, 'dependencies_admin_scripts' ) );

			// Save admin options.
			if ( WC_PD_Core_Compatibility::is_wc_version_gte_2_7() ) {
				add_action( 'woocommerce_admin_process_product_object', array( $this, 'process_product_data' ) );
			} else {
				add_action( 'woocommerce_process_product_meta', array( $this, 'process_meta' ), 10, 2 );
			}

			// Add the "Dependencies" tab in Product Data.
			if ( WC_PD_Core_Compatibility::is_wc_version_gte_2_5() ) {
				add_action( 'woocommerce_product_data_tabs', array( $this, 'dependencies_product_data_tab' ) );
			} else {
				add_action( 'woocommerce_product_write_panel_tabs', array( $this, 'dependencies_product_data_panel_tab' ) );
			}

			// Add the "Dependencies" tab content in Product Data.
			if ( WC_PD_Core_Compatibility::is_wc_version_gte_2_7() ) {
				add_action( 'woocommerce_product_data_panels', array( $this, 'dependencies_product_data_panel' ) );
			} else {
				add_action( 'woocommerce_product_write_panels', array( $this, 'dependencies_product_data_panel' ) );
			}
		}
	}

	/**
	 * Init textdomain.
	 *
	 * @return void
	 */
	public function init() {

		load_plugin_textdomain( 'woocommerce-product-dependencies', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
	}

	/**
	 * Include scripts.
	 */
	public function dependencies_admin_scripts() {

		$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		wp_register_script( 'wc-pd-writepanels', $this->plugin_url() . '/assets/js/wc-pd-writepanels' . $suffix . '.js', array(), $this->version );

		$screen    = get_current_screen();
		$screen_id = $screen ? $screen->id : '';

		if ( in_array( $screen_id, array( 'product' ) ) ) {
			wp_enqueue_script( 'wc-pd-writepanels' );
		}

	}
	/**
	 * Validates a product when adding to cart.
	 *
	 * @param  boolean  $add
	 * @param  int      $item_id
	 * @param  int      $quantity
	 * @return boolean
	 */
	public function add_to_cart_validation( $add, $item_id, $quantity ) {

		return $add && $this->evaluate_dependencies( $item_id );
	}

	/**
	 * Validates cart contents.
	 */
	public function check_cart_items() {

		$cart_items = WC()->cart->cart_contents;

		foreach ( $cart_items as $cart_item ) {

			$product = $cart_item[ 'data' ];

			$this->evaluate_dependencies( $product );
		}
	}

	/**
	 * Check conditions.
	 *
	 * @param  mixed  $item
	 * @return boolean
	 */
	public function evaluate_dependencies( $item ) {

		if ( is_a( $item, 'WC_Product' ) ) {

			if ( $item->is_type( 'variation' ) ) {
				$product_id = WC_PD_Core_Compatibility::get_parent_id( $item );
				$product    = wc_get_product( $product_id );
			} else {
				$product_id = WC_PD_Core_Compatibility::get_id( $item );
				$product    = $item;
			}

		} else {
			$product_id = absint( $item );
			$product    = wc_get_product( $product_id );
		}

		if ( ! $product ) {
			return;
		}

		$tied_product_ids = $this->get_required_ids( $product, $product_id );

		if ( WC_PD_Core_Compatibility::is_wc_version_gte_2_7() ) {
			$dependency_type  = absint( $product->get_meta( '_dependency_type', true ) );
		} else {
			$dependency_type  = absint( get_post_meta( $product_id, '_dependency_type', true ) );
		}

		$product_title = $product->get_title();
		$tied_products = array();

		// Ensure dependencies exist and are purchasable.
		if ( ! empty( $tied_product_ids ) ) {

			foreach ( $tied_product_ids as $id ) {

				$tied_product = wc_get_product( $id );

				if ( $tied_product && $tied_product->is_purchasable() ) {
					$tied_products[ $id ] = $tied_product;
				}
			}
		}

		$modifier = apply_filters( 'wc_pd_dependency_selection_modifier', 'or' );

		if ( ! empty( $tied_products ) ) {

			$tied_product_ids = array_keys( $tied_products );
			$matched_products = 0;
			// Check cart.
			if ( $dependency_type === 2 || $dependency_type === 3 ) {

				$cart_contents = WC()->cart->cart_contents;

				foreach ( $cart_contents as $cart_item ) {

					$product_id   = $cart_item[ 'product_id' ];
					$variation_id = $cart_item[ 'variation_id' ];

					if ( in_array( $product_id, $tied_product_ids ) || in_array( $variation_id, $tied_product_ids ) ) {

						if ( 'or' === $modifier ) {
							return true;
						}
						if ( 'category_ids' === $this->get_selection_type( $product ) ) {

							$category_ids  = $this->get_category_ids( $product );

							foreach ( $category_ids as $category_id ) {

								$category_product_ids = $this->get_category_product_ids( $category_id );

								if ( ! empty( $category_product_ids ) && in_array( $product_id, $category_product_ids ) ) {
									$matched_products = $matched_products + count( $category_product_ids );
								}
							}
						} else if ( 'product_ids' === $this->get_selection_type( $product ) ) {
							$matched_products++;
						}

					}
				}

				if ( 'and' === $modifier ) {
					if ( $matched_products === count( $tied_products ) ) {
						return true;
					}
				}
			}
			// Check ownership.
			if ( is_user_logged_in() && ( $dependency_type === 1 || $dependency_type === 3 ) ) {

				$current_user       = wp_get_current_user();
				$is_owner           = false;
				$bought_products    = 0;
				$matched_categories = array();

				foreach ( $tied_product_ids as $id ) {

					if ( wc_customer_bought_product( $current_user->user_email, $current_user->ID, $id ) ) {

						if ( 'or' === $modifier ) {
							$is_owner = true;
							break;
						}

						if ( 'product_ids' === $this->get_selection_type( $product ) ) {
							$bought_products++;
						} elseif ( 'category_ids' === $this->get_selection_type( $product ) ) {

							$category_ids  = $this->get_category_ids( $product );

							foreach ( $category_ids as $category_id ) {

								$category_product_ids = $this->get_category_product_ids( $category_id );

								if (  ! empty( $category_product_ids )  && in_array( $id, $category_product_ids ) ) {

									if ( in_array( $category_id, $matched_categories ) ) {
										continue;
									} else {
										$matched_categories[] = $category_id;
										$bought_products      = $bought_products + count( $category_product_ids );
									}
								}
							}
						}
					}
				}

				if ( 'and' === $modifier ) {
					if ( $bought_products === count( $tied_product_ids ) ) {
						$is_owner = true;
					}
				}

				if ( ! $is_owner ) {

					if ( 'product_ids' === $this->get_selection_type( $product ) ) {
						$merged_titles = WC_PD_Helpers::merge_product_titles( $tied_products, $modifier );
					} elseif ( 'category_ids' === $this->get_selection_type( $product ) ) {
						$category_ids  = $this->get_category_ids( $product );
						$merged_titles = WC_PD_Helpers::merge_categories_titles( $category_ids, $modifier );
						$merged_titles = sprintf( __( 'a product from the %s category', 'woocommerce-product-dependencies' ), $merged_titles );
					}

					if ( $dependency_type === 1 ) {
						wc_add_notice( sprintf( __( 'Access to &quot;%2$s&quot; is restricted only to customers who have bought %1$s.', 'woocommerce-product-dependencies' ), $merged_titles, $product_title ), 'error' );
					} else {
						wc_add_notice( sprintf( __( 'Access to &quot;%2$s&quot; is restricted only to customers who have bought %1$s. Alternatively, access to this item will be granted after adding a %1$s to the cart.', 'woocommerce-product-dependencies' ), $merged_titles, $product_title ), 'error' );
					}
					return false;
				}

			} else {

				if ( 'product_ids' === $this->get_selection_type( $product ) ) {
					$merged_titles = WC_PD_Helpers::merge_product_titles( $tied_products, $modifier );
				} elseif ( 'category_ids' === $this->get_selection_type( $product ) ) {
					$category_ids  = $this->get_category_ids( $product );
					$merged_titles = WC_PD_Helpers::merge_categories_titles( $category_ids, $modifier );
					$merged_titles = sprintf( __( 'a product from the %s category', 'woocommerce-product-dependencies' ), $merged_titles );
				}

				$msg = '';

				if ( $dependency_type === 1 ) {
					$msg = __( 'Access to &quot;%2$s&quot; is restricted only to to customers who have bought %1$s. The verification is automatic and simply requires you to be <a href="%3$s">logged in</a>.', 'woocommerce-product-dependencies' );
				} elseif ( $dependency_type === 2 ) {
					$msg = __( '&quot;%2$s&quot; can be purchased only in combination with %1$s. Access to this item will be granted after adding a %1$s to the cart.', 'woocommerce-product-dependencies' );
				} else {
					$msg = __( '&quot;%2$s&quot; requires the purchase of %1$s. Ownership can be verified by simply <a href="%3$s">logging in</a>. Alternatively, access to this item will be granted after adding a %1$s to the cart.', 'woocommerce-product-dependencies' );
				}
				wc_add_notice( sprintf( $msg, $merged_titles, $product_title, wp_login_url() ), 'error' );
				return false;
			}
		}
		return true;
	}

	/**
	 * Returns an array with all dependent product ids.
	 *
	 * @param  WC_Product $product
	 * @param  integer    $product_id
	 * @return array      $dependent_ids
	 */
	public function get_required_ids( $product, $product_id ) {

		$dependent_ids  = array();

		$selection_type = $this->get_selection_type( $product );

		if ( 'product_ids' === $selection_type ) {

			if ( WC_PD_Core_Compatibility::is_wc_version_gte_2_7() ) {
				$dependent_ids = $product->get_meta( '_tied_products', true );
			} else {
				$dependent_ids = (array) get_post_meta( $product_id, '_tied_products', true );
			}

		} elseif ( 'category_ids' === $selection_type ) {

			$category_ids = $this->get_category_ids( $product );

			if ( ! empty( $category_ids ) ) {

				$query_results = new WP_Query( array(
					'post_type'   => 'product',
					'post_status' => 'publish',
					'fields'      => 'ids',
					'tax_query'   => array(
						array(
							'taxonomy' => 'product_cat',
							'field'    => 'term_id',
							'terms'    => $category_ids,
							'operator' => 'IN',
						)
					)
				) );

				$dependent_ids = $query_results->posts;
			}
		}

		return $dependent_ids;
	}

	/**
	 * Returns the product dependency selection type.
	 *
	 * @param  WC_Product $product
	 * @return string     $selection_type
	 */
	public function get_selection_type( $product ) {

		if ( WC_PD_Core_Compatibility::is_wc_version_gte_2_7() ) {
			$selection_type = $product->get_meta( '_dependency_selection_type', true );
		} else {
			$selection_type = (array) get_post_meta( $product_id, '_dependency_selection_type', true );
		}

		$selection_type = in_array( $selection_type, array( 'product_ids', 'category_ids' ) ) ? $selection_type : 'product_ids';

		return $selection_type;
	}

	/**
	 * Returns an array with all saved category ids.
	 *
	 * @param  WC_Product $product
	 * @return array      $category_ids
	 */
	public function get_category_ids( $product ) {

		if ( WC_PD_Core_Compatibility::is_wc_version_gte_2_7() ) {
			$category_ids = $product->get_meta( '_tied_categories', true );
		} else {
			$category_ids = (array) get_post_meta( $product_id, '_tied_categories', true );
		}

		return $category_ids;
	}

	/**
	 * Returns an array with all product IDs in a category
	 *
	 * @param  integer    $category_id
	 * @return array      $product_ids
	 */
	public function get_category_product_ids( $category_id ) {

		$product_ids = array();

			if ( ! empty( $category_id ) ) {

				$query_results = new WP_Query( array(
					'post_type'   => 'product',
					'post_status' => 'publish',
					'fields'      => 'ids',
					'tax_query'   => array(
						array(
							'taxonomy' => 'product_cat',
							'field'    => 'term_id',
							'terms'    => $category_id,
							'operator' => 'IN',
						)
					)
				) );

				$product_ids = $query_results->posts;
			}

		return $product_ids;
	}

	/*
	|--------------------------------------------------------------------------
	| Admin Filters.
	|--------------------------------------------------------------------------
	*/

	/**
	 * Add Product Data tab.
	 *
	 * @return void
	 */
	public function dependencies_product_data_panel_tab() {
		echo '<li class="tied_products_tab related_product_options linked_product_options"><a href="#tied_products_data">' . __( 'Dependencies', 'woocommerce-product-dependencies' ) . '</a></li>';
	}

	/**
	 * Add the "Product Dependencies" panel tab.
	 *
	 * @param  array  $tabs
	 * @return array
	 */
	public function dependencies_product_data_tab( $tabs ) {

		$tabs[ 'dependencies' ] = array(
			'label'  => __( 'Dependencies', 'woocommerce-product-dependencies' ),
			'target' => 'tied_products_data',
			'class'  => array( 'show_if_simple', 'show_if_variable', 'show_if_bundle', 'show_if_composite', 'linked_product_options' )
		);

		return $tabs;
	}

	/**
	 * Add Product Data tab section.
	 *
	 * @return void
	 */
	public function dependencies_product_data_panel() {

		global $post, $product_object;

		if ( WC_PD_Core_Compatibility::is_wc_version_gte_2_7() ) {
			$tied_products   = $product_object->get_meta( '_tied_products', true );
			$dependency_type = $product_object->get_meta( '_dependency_type', true );
			$selection_type  = $product_object->get_meta( '_dependency_selection_type', true );
		} else {
			$tied_products   = get_post_meta( $post->ID, '_tied_products', true );
			$dependency_type = get_post_meta( $post->ID, '_dependency_type', true );
			$selection_type  = get_post_meta( $post->ID, '_dependency_selection_type', true );
		}

		if ( ! $dependency_type ) {
			$dependency_type = 3;
		}

		$product_id_options  = array();

		$product_categories  = ( array ) get_terms( 'product_cat', array( 'get' => 'all' ) );
		$selected_categories = ( empty( $product_object->get_meta( '_tied_categories', true ) ) ) ? array() : $product_object->get_meta( '_tied_categories', true );

		$selection_type      = in_array( $selection_type, array( 'product_ids', 'category_ids' ) ) ? $selection_type : 'product_ids';

		if ( $tied_products ) {
			foreach ( $tied_products as $item_id ) {

				$title = WC_PD_Helpers::get_product_title( $item_id );

				if ( $title ) {
					$product_id_options[ $item_id ] = $title;
				}
			}
		}

		?>
		<div id="tied_products_data" class="panel woocommerce_options_panel wc-metaboxes-wrapper">

			<?php

				woocommerce_wp_select( array(
					'id'            => 'product_dependencies_dropdown',
					'label'         => __( 'Product Dependencies', 'woocommerce-product-dependencies' ),
					'options'       => array(
						'product_ids'  => __( 'Select products', 'woocommerce-product-dependencies' ),
						'category_ids' => __( 'Select categories', 'woocommerce-product-dependencies' )
					),
					'value'         => $selection_type
				) );
			?>

			<label>
				<?php _e( 'Product Dependencies', 'woocommerce-product-dependencies' ); ?>
			</label>

			<div id="product_ids_dependencies_choice" class="form-field">
				<p class="form-field">
					<?php

					if ( WC_PD_Core_Compatibility::is_wc_version_gte_2_7() ) {

						?><select id="tied_products" name="tied_products[]" class="wc-product-search" multiple="multiple" style="width: 75%;" data-limit="500" data-action="woocommerce_json_search_products_and_variations" data-placeholder="<?php echo  __( 'Search for products and variations&hellip;', 'woocommerce-product-dependencies' ); ?>"><?php

							if ( ! empty( $product_id_options ) ) {

								foreach ( $product_id_options as $product_id => $product_name ) {
									echo '<option value="' . $product_id . '" selected="selected">' . $product_name . '</option>';
								}
							}

						?></select><?php

					} elseif ( WC_PD_Core_Compatibility::is_wc_version_gte_2_3() ) {

						?><input type="hidden" id="tied_products" name="tied_products" class="wc-product-search" style="width: 75%;" data-placeholder="<?php _e( 'Search for products&hellip;', 'woocommerce-product-dependencies' ); ?>" data-action="woocommerce_json_search_products" data-multiple="true" data-selected="<?php

							echo esc_attr( json_encode( $product_id_options ) );

						?>" value="<?php echo implode( ',', array_keys( $product_id_options ) ); ?>" /><?php

					} else {

						?><select id="tied_products" multiple="multiple" name="tied_products[]" data-placeholder="<?php _e( 'Search for products&hellip;', 'woocommerce-product-dependencies' ); ?>" class="ajax_chosen_select_products"><?php

							if ( ! empty( $product_id_options ) ) {

								foreach ( $product_id_options as $product_id => $product_name ) {
									echo '<option value="' . $product_id . '" selected="selected">' . $product_name . '</option>';
								}
							}
						?></select><?php

					}

					echo WC_PD_Core_Compatibility::wc_help_tip( __( 'Restrict product access based on the ownership or purchase of <strong>any</strong> product or variation added to this list.', 'woocommerce-product-dependencies' ) );

					?>
				</p>
			</div>

			<div id="category_ids_dependencies_choice" class="form-field" >
				<p class="form-field">
					<select id="tied_categories" name="tied_categories[]" style="width: 75%" class="multiselect wc-enhanced-select" multiple="multiple" data-placeholder="<?php echo  __( 'Select product categories&hellip;', 'woocommerce-product-dependencies' ); ?>"><?php

						if ( ! empty( $product_categories ) ) {

							foreach ( $product_categories as $product_category )
								echo '<option value="' . $product_category->term_id . '" ' . selected( in_array( $product_category->term_id, $selected_categories ), true, false ).'>' . $product_category->name . '</option>';
						}

					?></select>
				</p>
			</div>

			<div class="form-field">
				<p class="form-field">
					<label><?php _e( 'Dependency Type', 'woocommerce-product-dependencies' ); ?>
					</label>
					<select name="dependency_type" id="dependency_type" style="min-width:150px;">
						<option value="1" <?php echo $dependency_type == 1 ? 'selected="selected"' : ''; ?>><?php _e( 'Ownership', 'woocommerce-product-dependencies' ); ?></option>
						<option value="2" <?php echo $dependency_type == 2 ? 'selected="selected"' : ''; ?>><?php _e( 'Purchase', 'woocommerce-product-dependencies' ); ?></option>
						<option value="3" <?php echo $dependency_type == 3 ? 'selected="selected"' : ''; ?>><?php _e( 'Either', 'woocommerce-product-dependencies' ); ?></option>
					</select>
				</p>
			</div>
		</div>
	<?php
	}

	/**
	 * Save dependencies data. WC >= 2.7.
	 *
	 * @param  WC_Product  $product
	 * @return void
	 */
	public function process_product_data( $product ) {

		if ( ! empty( $_POST[ 'tied_categories' ] ) && is_array( $_POST[ 'tied_categories' ] ) ) {
			$tied_categories = array_map( 'intval', $_POST[ 'tied_categories' ] );
			$product->add_meta_data( '_tied_categories', $tied_categories, true );
		} else {
			$product->delete_meta_data( '_tied_categories' );
		}

		if ( ! isset( $_POST[ 'tied_products' ] ) || empty( $_POST[ 'tied_products' ] ) ) {
			$product->delete_meta_data( '_tied_products' );
		} elseif ( isset( $_POST[ 'tied_products' ] ) && ! empty( $_POST[ 'tied_products' ] ) ) {

			$tied_ids = $_POST[ 'tied_products' ];

			if ( is_array( $tied_ids ) ) {
				$tied_ids = array_map( 'intval', $tied_ids );
			} else {
				$tied_ids = array_filter( array_map( 'intval', explode( ',', $tied_ids ) ) );
			}

			$product->add_meta_data( '_tied_products', $tied_ids, true );
		}

		if ( ! empty( $_POST[ 'dependency_type' ] ) ) {
			$product->add_meta_data( '_dependency_type', stripslashes( $_POST[ 'dependency_type' ] ), true );
		}

		if ( ! empty( $_POST[ 'product_dependencies_dropdown' ] ) ) {
			$product->add_meta_data( '_dependency_selection_type', stripslashes( $_POST[ 'product_dependencies_dropdown' ] ), true );
		}
	}

	/**
	 * Save dependencies meta. WC <= 2.6.
	 *
	 * @param  int      $post_id
	 * @param  WC_Post  $post
	 * @return void
	 */
	public function process_meta( $post_id, $post ) {

		global $post;

		if ( ! empty( $_POST[ 'tied_categories' ] ) && is_array( $_POST[ 'tied_categories' ] ) ) {
			$tied_categories = array_map( 'intval', $_POST[ 'tied_categories' ] );
			update_post_meta( $post_id, '_tied_categories', $tied_categories );
		} else {

			delete_post_meta( $post_id, '_tied_categories' );
		}

		if ( ! isset( $_POST[ 'tied_products' ] ) || empty( $_POST[ 'tied_products' ] ) ) {

			delete_post_meta( $post_id, '_tied_products' );

		} elseif ( isset( $_POST[ 'tied_products' ] ) && ! empty( $_POST[ 'tied_products' ] ) ) {

			$tied_ids = $_POST[ 'tied_products' ];

			if ( is_array( $tied_ids ) ) {
				$tied_ids = array_map( 'intval', $tied_ids );
			} else {
				$tied_ids = array_filter( array_map( 'intval', explode( ',', $tied_ids ) ) );
			}

			update_post_meta( $post_id, '_tied_products', $tied_ids );
		}

		if ( ! empty( $_POST[ 'dependency_type' ] ) ) {
			update_post_meta( $post_id, '_dependency_type', stripslashes( $_POST[ 'dependency_type' ] ) );
		}

		if ( ! empty( $_POST[ 'product_dependencies_dropdown' ] ) ) {
			update_post_meta( $post_id, '_dependency_selection_type', stripslashes( $_POST[ 'product_dependencies_dropdown' ] ) );
		}
	}

	/*
	|--------------------------------------------------------------------------
	| Deprecated methods.
	|--------------------------------------------------------------------------
	*/

	/**
	 * Check conditions.
	 *
	 * @deprecated  1.1.0
	 *
	 * @param  int  $item_id
	 * @return boolean
	 */
	public function woo_tied_evaluate_access( $id ) {
		_deprecated_function( __METHOD__ . '()', '1.1.0', __CLASS__ . '::evaluate_access()' );
		return WC_Product_Dependencies()->evaluate_dependencies( $id );
	}
}

/**
 * Returns the main instance of WC_Product_Dependencies to prevent the need to use globals.
 *
 * @since  1.1.0
 * @return WC_Product_Dependencies
 */
function WC_Product_Dependencies() {
  return WC_Product_Dependencies::instance();
}

// Backwards compatibility with v1.0.
$GLOBALS[ 'woocommerce_restricted_access' ] = WC_Product_Dependencies();
