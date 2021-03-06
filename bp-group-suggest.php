<?php

/**
 * PLugin Name: BuddyPress Group Suggestions
 * Author: Brajesh Singh 
 * Plugin URI: http://buddydev.com/plugins/bp-groups-suggest/
 * Author URI: http://buddydev.com
 * Version: 1.0.4
 * License:GPL
 * Last Updated: May 29, 2016
 * Description: Simple Group suggestion widget based on friends Groups 
 * Special thanks to @GWU for the idea of this widget
 */

/**
 * Helper class for The BuddyPress Group suggestions
 */
class BuddyDev_Group_Suggestions_Helper {

	private static $instance = null;

	private function __construct() {

		//load script
		add_action( 'bp_enqueue_scripts', array( $this, 'load_js' ) );
		//ajax handling of hiding the suggestion
		add_action( 'wp_ajax_group_suggest_remove_suggestion', array( $this, 'hide_suggestion' ) );

		//load text domain
		add_action( 'bp_loaded', array( $this, 'load_textdomain' ), 2 );
	}

//public method for getting the instance/initializing
	public static function get_instance() {

		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	//ajax helper for hiding,it keeps the hidden group in usermeta 
	public function hide_suggestion() {

		$suggestion_id = isset( $_POST['suggestion_id'] ) ? absint( $_POST['suggestion_id'] ) : 0;
		
		check_ajax_referer( 'group-suggestion-remove-' . $suggestion_id );

		if ( empty( $suggestion_id ) || ! is_user_logged_in() ) {
			return;
		}
		
	
		$user_id = bp_loggedin_user_id();
		$excluded = get_user_meta( $user_id, 'hidden_group_suggestions', true );
		$excluded = (array) ( $excluded );
		$excluded[] = $suggestion_id;

		update_user_meta( $user_id, 'hidden_group_suggestions', $excluded );

		exit( 0 );
	}

	//get the hidden group ids as an array, yep u read it right
	public static function get_hidden( $user_id = null ) {
		
		if ( ! $user_id ) {
			$user_id = bp_loggedin_user_id();
		}
		
		return get_user_meta( $user_id, 'hidden_group_suggestions', true );
	}

	//show the list here
	public static function suggestions_list( $limit, $user_id = null ) {
		global $bp, $wpdb;

		if ( ! $user_id ) {
			$user_id = bp_loggedin_user_id();
		}
			
		
		//who are the friends of current user, let us have their ids as array
		$my_friends = (array) friends_get_friend_user_ids( $user_id ); //get all friend ids
		
		if ( empty( $my_friends ) ) {
			//the user has no friend list, what should we do
			self::message( __ ( "We don't have any suggestions yet.", 'bp-group-suggest' ) );
			return ;
			
		}
		//find friend's groups of the user
		$friends_list = "(" . join( ",", $my_friends ) . ")";

		$friends_groups_sql = "SELECT DISTINCT group_id FROM  {$bp->groups->table_name} g, {$bp->groups->table_name_members} m WHERE g.id=m.group_id AND g.status='public' AND m.user_id in {$friends_list} AND is_confirmed= 1";
		$friends_groups = $wpdb->get_col( $friends_groups_sql );

		//get all current user  groups, includes pending,already a member, banned ,kicked etc
		$my_all_groups_sql = $wpdb->prepare( "SELECT DISTINCT group_id FROM {$bp->groups->table_name_members}  WHERE user_id = %d  ", $user_id );
		$my_groups = $wpdb->get_col( $my_all_groups_sql );

		//groups the user has hidden
		$my_excluded = (array) self::get_hidden( $user_id );

		//make an array of users group+groups hidden by user
		$excluded = array_merge( $my_groups, $my_excluded );
		$excluded = array_unique( $excluded );
		//so here we get the possible groups?
		$possible_groups = array_diff( $friends_groups, $excluded ); //we will store the possible group ids here


		if ( ! empty( $possible_groups ) ) {
			shuffle( $possible_groups ); //randomize
			$possible_groups = array_slice( $possible_groups, 0, $limit );
		}
		if ( empty( $possible_groups ) ) {
			self::message( __ ( "We don't have any suggestions yet.", 'bp-group-suggest' ) );
			return ;
		}

		//how to minim ize queries here by getting all the details in one query ?
		//
        //if we find and output individual group trough stanndard way,m that will be too many quesries, let us find it in a single query
		$list = "(" . join( ',', $possible_groups ) . ")";
		$query = "SELECT * FROM {$bp->groups->table_name} WHERE id IN {$list}";

		$groups = $wpdb->get_results( $query );
		shuffle( $groups ); //shuffle it
		if ( ! empty( $groups ) ):
			?>
			<ul id="groups-list" class="item-list suggested-group-item-list">
				<?php foreach ( $groups as $group ): ?>
					<li>
						<?php
						$group_link = bp_get_group_permalink( $group );
						$group_name = $group->name;
						$group->is_member = false;
						?>
						<div class="item-avatar">
							<a href="<?php echo $group_link; ?>"><?php echo bp_core_fetch_avatar( array( 'type' => 'thumb', 'width' => 25, 'height' => 25, 'object' => 'group', 'item_id' => $group->id ) ); ?></a>
						</div>

						<div class="item">
							<div class="item-title">
								<a href="<?php echo $group_link; ?>"><?php echo $group_name; ?></a>
							</div>
						</div>
						<div class="action">
							<?php self::get_hide_suggestion_link( $group->id ); ?>

							<?php echo bp_get_group_join_button( $group ); ?>
						</div>
						<div class="clear"></div>

					</li>

				<?php endforeach; ?>
			</ul>
		<?php else: ?>
			<div id="message" class="info">
				<p><?php _e( "We don't have enough details to suggest a group yet.", 'buddypress' ) ?></p>
			</div>

		<?php endif; ?>

		<?php
	}

	public static function message( $message ) {
		?>
			<div id="message" class="info">
				<p><?php echo $message;?></p>
			</div>
		<?php
	}
	//ui
	//get the link for hide (x) button
	public static function get_hide_suggestion_link( $possible_group_id ) {
		$url = bp_get_root_domain() . "/remove-group-suggestion/?suggest_id=" . $possible_group_id . "&_wpnonce=" . wp_create_nonce( 'group-suggestion-remove-' . $possible_group_id );
		?>
		<span class="remove-group-suggestion"><a href="<?php echo $url; ?>" title="<?php __( 'Hide this suggestion', 'bp-group-suggest' ); ?>">x</a></span>
		<?php
	}

	//load javascript if user is logged in and is viewing the site
	public function load_js() {
		if ( ! is_user_logged_in() || is_admin() ) {
			return;
		}
			
		$gsuggest_url = plugin_dir_url( __FILE__ ); //with a trailing slash
		wp_enqueue_script( "group-suggest-js", $gsuggest_url . "group-suggest.js", array( "jquery" ) );
	}


	public function load_textdomain() {

		load_plugin_textdomain('bp-group-suggest', false, plugin_basename( dirname( __FILE__ ) ) . '/languages' );
	}

}

//end of helper class
/**
 * BuddyPress group Suggestions Widget
 */

class BuddyDev_Group_Suggestions_Widget extends WP_Widget {

	public function __construct() {
		parent::__construct( false, __( '(BuddyPress) Group Suggestions', 'bp-group-suggest' ), array( 'classname'=> 'buddypress bp-group-suggestions-widget') );
	}

	//display

	public function widget( $args, $instance ) {
		
		if ( ! is_user_logged_in() ) {
			return; //do not show to non logged in user
		}

		extract( $args );
		echo $before_widget .
			$before_title .
				$instance['title'] .
			$after_title;
			BuddyDev_Group_Suggestions_Helper::suggestions_list( $instance['max'] );
		echo $after_widget;
	}

	//update
	public function update( $new_instance, $old_instance ) {
		$instance = $old_instance;
		$instance['title'] = strip_tags( $new_instance['title'] );
		$instance['max'] = absint( $new_instance['max'] );

		return $instance;
	}

	//widget options    
	public function form( $instance ) {

		$instance = wp_parse_args( (array) $instance, array( 'title' => __( 'Group Suggestions', 'bp-group-suggest' ), 'max' => 5 ) );
		$title = strip_tags( $instance['title'] );
		$max = absint( $instance['max'] );
		?>
		<p>
			<label for="bp-groups-suggest-widget-title"><?php _e( 'Title', 'bp-group-suggest' ); ?>
				<input type="text" id="<?php echo $this->get_field_id( 'title' ); ?>" name="<?php echo $this->get_field_name( 'title' ); ?>" class="widefat" value="<?php echo esc_attr( $title ); ?>" />
			</label>
		</p>
		<p>
			<label for="bp-show-groups-widget-per-page"><?php _e( 'Max Number of suggestions:', 'bp-group-suggest' ); ?>
				<input class="widefat" id="<?php echo $this->get_field_id( 'max' ); ?>" name="<?php echo $this->get_field_name( 'max' ); ?>" type="text" value="<?php echo esc_attr( $max ); ?>" style="width: 30%" />
			</label>
		</p>
	<?php
	}

}

//register widget
function buddydev_register_group_suggestions_widget() {
	register_widget( 'BuddyDev_Group_Suggestions_Widget' );
}

add_action( 'bp_widgets_init', 'buddydev_register_group_suggestions_widget' );


//initialize
BuddyDev_Group_Suggestions_Helper::get_instance();
