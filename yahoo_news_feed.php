<?php
/*
Plugin Name: Yahoo! News Feed
Plugin URI: http://www.prelovac.com/wordpress-plugins/yahoo-news-feed
Description: This plugin generates a Yahoo! News feed in NewsML1.2 format
Version: 1.0.1
Author: Vladimir Prelovac
Author URI: http://www.prelovac.com/
*/

global $wpdb, $ynf_plugin_slug, $ynf_plugin_name, $ynf_plugin_dir, $ynf_default_options, 
    $ynf_text_domain, $ynf_feed_file;

$ynf_plugin_slug = 'yahoo-news-feed';
$ynf_plugin_name = 'Yahoo! News Feed';
$ynf_plugin_dir = get_settings('siteurl') . "/wp-content/plugins/$ynf_plugin_slug/";
$ynf_feed_file = 'yahoo-news.xml';
$ynf_text_domain = "$ynf_plugin_slug-domain";
$ynf_default_options = array(
	'days'			        => 7,
	'number_of_items' 	    => 10,
    'excluded_categories'   => array(),
);

$ynf_plugin_url = defined('WP_PLUGIN_URL') ? trailingslashit(WP_PLUGIN_URL . '/' . dirname(plugin_basename(__FILE__))) : trailingslashit(get_bloginfo('wpurl')) . PLUGINDIR . '/' . dirname(plugin_basename(__FILE__)); 

load_plugin_textdomain($ynf_text_domain);

ynf_hook();

/**
* @desc     Adds necessary hooks to generate the feed
* @return   void
*/
function ynf_hook()
{
    add_action('publish_post', 'ynf_publish_post_hook');
    add_action('deleted_post', 'ynf_deleted_post_hook');
    add_action('transition_post_status', 'ynf_transition_post_status_hook', 10, 3); 
}

/**
* @desc     Removes all the hooks that were applied for feed generating
* @return   void
*/
function ynf_remove_hook()
{
    remove_action('publish_post', 'ynf_publish_post_hook', 1);
    remove_action('deleted_post', 'ynf_deleted_post_hook', 1);
    remove_action('transition_post_status', 'ynf_transition_post_status_hook', 1); 
}

/**
* @desc     Hooks into publish_post 
* Will be triggered everytime a post/page is published
* @param    int The post/page ID
* @return   void
*/
function ynf_publish_post_hook($post_ID)
{
    // we only care about posts
    if (get_post_type($post_ID) == 'post')
    {
        ynf_generate_feed();
    }
}

/**
* @desc     Hooks into deleted_post
* Will be triggered after a post/page gets deleted (poor the guy)
* @param    int The post/page ID
* @return   void
*/
function ynf_deleted_post_hook($post_ID)
{
    // we only care about posts
    // the post has been deleted, so must check this way
    global $post;
    if ($post->post_type == 'post')
    {
        ynf_generate_feed();
    }
}

/**
* @desc Hooks into transition_post_status
* Will be triggered everytime a post/page's status is changed
* (eg. from private to publish or draft to publish and so on)
* @param    string  The new status of the post/page
* @param    string  The old status of the post/page
* @param    object  The post/page which has its status changed
* @return   void
*/
function ynf_transition_post_status_hook($new_status, $old_status, $post)
{
    // we only care about publish posts
    if ($new_status == 'publish' && $old_status != 'publish' && $post->post_type == 'post')
    {
        ynf_generate_feed();
    }
}

/**
* @desc     Generates the feed
* @return   void
*/
function ynf_generate_feed()
{
    ynf_remove_hook();
    
    global $wpdb, $ynf_plugin_slug, $ynf_plugin_dir, $ynf_default_options, $ynf_text_domain, 
    $ynf_feed_file;
    
    if (!$options = get_option($ynf_plugin_slug))
    {
        $options = $ynf_default_options;
    }
    
    if (!isset($options['excluded_categories']))
    {
        $options['excluded_categories'] = array();
    }
    
    $excluded_post_ids = get_objects_in_term($options['excluded_categories'], 'category');
    $exclude_statement = count($excluded_post_ids) ? 
        'AND ID NOT IN(' . implode(',', $excluded_post_ids) . ')' : '';
    
    $starting_day = date('Y-m-d 00:00:00', time() - $options['days'] * 24 * 60 * 60);
    $sql = "SELECT DISTINCT ID FROM {$wpdb->posts} 
    WHERE post_date > '$starting_day'
    AND post_status = 'publish'
    AND post_type = 'post' $exclude_statement
    ORDER BY ID DESC
    LIMIT {$options['number_of_items']}";
    
    $rs = $wpdb->get_results($sql);
    
    $post_ids = array();
    foreach ($rs as $myitem)
    {
        $post_ids[] = $myitem->ID;
    }
    
    if (!count($post_ids))
    {
        $feed_content = "<!--
     There are no posts that match your settings ({$options['number_of_items']} posts in the last {$options['days']} days)
-->";
        ynf_write_feed($feed_content);
        return;
    }
    
    $args = array(
        'include'   => implode(',', $post_ids),
    );
    
    // actually we can use pure MySQL queries to get the post data
    // but better let WordPress code handle the job
    global $post;
    $chosen_posts = get_posts($args);
    
    $news_xml = '';
    
    ob_start();
    
    foreach($chosen_posts as $post)
    {
        setup_postdata($post);
        $news_item_id = md5(get_the_ID() . get_bloginfo('url'));
?>
        <NewsItem>
           <Identification>
             <NewsIdentifier>
               <ProviderId><?php echo get_bloginfo('title') ?></ProviderId>
               <DateId><?php the_time('Ymd')?></DateId>
               <NewsItemId><?php echo $news_item_id?></NewsItemId>
               <RevisionId PreviousRevision="0" Update="N">1</RevisionId>
               <PublicIdentifier>urn:newsml:<?php echo ynf_get_host()?>:<?php the_time('Ymd')?>:<?php echo $news_item_id?></PublicIdentifier>
             </NewsIdentifier>
             <NameLabel><?php echo $post->post_name?></NameLabel>
           </Identification>
           <NewsManagement>
             <Status FormalName="Usable"/>
             <AssociatedWith FormalName="linkbox" NewsItem="<?php the_permalink()?>"/>
           </NewsManagement>
           <NewsComponent>
             <NewsLines>
               <HeadLine><?php the_title()?></HeadLine>
               <DateLine></DateLine>
               <CopyrightLine><?php echo date('Y')?> <?php bloginfo('name')?></CopyrightLine>
             </NewsLines>
             <DescriptiveMetadata>
               <Language FormalName="<?php echo current(explode('-', get_bloginfo('language')))?>"/>
             </DescriptiveMetadata>
             <NewsComponent>
               <ContentItem>
                 <MediaType FormalName="Text"/>
                 <Format FormalName="NITF3.2"/>
                 <DataContent>
        <?php the_excerpt()?>
                 </DataContent>
               </ContentItem>
             </NewsComponent>
           </NewsComponent>
         </NewsItem>
<?php
    }
    
    $news_xml .= ob_get_clean();
    
    // everything is on our hand now
    // try writing the data into the feed file
    ynf_write_feed($news_xml);
}

/**
* @desc     Writes the feed content into the file
* @param    The content string
* @return   void
*/
function ynf_write_feed($items_string = '')
{
    global $ynf_feed_file;
    
    $yahoo_feed_content = '<?xml version="1.0" encoding="' . get_bloginfo('charset') . '"?>
<NewsML>
 <Catalog Href="' . get_bloginfo('url') . '/dtd/catalog.xml"/>
 <NewsEnvelope>
   <DateAndTime>' . date('Ymd\\THis\Z') .  '</DateAndTime>
 </NewsEnvelope>
' . $items_string . '
</NewsML>';

    if (!$handle = fopen("../$ynf_feed_file", 'w'))
    {
        ynf_write_log('CRITICAL - ' . get_bloginfo('url') . "/$ynf_feed_file is NOT writable.");
    }
    
    if (fwrite($handle, $yahoo_feed_content) === false) 
    {
        ynf_write_log('CRITICAL - ' . get_bloginfo('url') . "/$ynf_feed_file is NOT writable.");
    }
    
    // perfect
    fclose($handle);
}

/**
* @desc     A small helper to get the host name of the current blog
* @return   The host string (example.com, google.com etc.)
*/
function ynf_get_host()
{
    static $ynf_host;
    if (empty($ynf_host))
    {
        $segments = parse_url(get_bloginfo('url')); 
        $ynf_host = $segments['host'];
    }
    
    return $ynf_host;
}

/**
* @desc     Hooks into plugin install to prepare the default options
* @return   void
*/
function ynf_install()
{
	global $ynf_default_options, $ynf_plugin_slug;
    add_option($ynf_plugin_slug, $ynf_default_options);
	ynf_generate_feed();
}

register_activation_hook(__FILE__, 'ynf_install');

/**
* @desc     Hooks into init to handle the options saving
* @return   void
*/
function ynf_request_handler()
{
    global $ynf_plugin_slug, $ynf_text_domain;
    
    // if $_POST['ynf_action'] is not set, this request doesn't belong to us!
    // let it go
    if (!isset($_POST['ynf_action'])) return false;
    
    // remember to verify the nonce for security purpose.
    if (!wp_verify_nonce($_POST['_nonce'], $ynf_plugin_slug))
    {
        die(__('Security check failed. Please try refreshing.', $ynf_text_domain));
    }
    
	switch($_POST['ynf_action'])
	{
		case 'options':
			ynf_save_options();
			break;
		default:
			return false;
	}
	exit();
}

add_action('init', 'ynf_request_handler', 5);

/**
* @desc     Saves the plugin settings
* @return   void
*/
function ynf_save_options()
{
	global $ynf_text_domain, $ynf_plugin_slug;
	
	$errors = array();
	$number_of_items = trim($_POST['number_of_items']);
    
    // the number of item should be a number greater than 0
    if (!is_numeric($number_of_items) || $number_of_items < 1) 
    {
        $errors[] = __('Invalid number of posts', $ynf_text_domain);
    }
    
    // so is the days
    $days = trim($_POST['days']);
    if (!is_numeric($days) || $days < 1)
    { 
        $errors[] = __('Invalid number of days', $ynf_text_domain);
    }
    
    // something went wrong with the numbers. Don't process any further
    if (count($errors))
    {
        die ('<ul><li>' . implode('</li><li>', $errors) . '</li></ul>');
    }
    
    $excluded_categories = array();
    if (isset($_POST['excluded_categories']))
    {
        // better validate
        foreach ($_POST['excluded_categories'] as $cat_id)
            $excluded_categories[] = intval($cat_id);
    }
    
    $ynf_options = array(
        'days'                  => $days,
        'number_of_items'       => $number_of_items,
        'excluded_categories'   => $excluded_categories,
    );
    
    // now save
    update_option($ynf_plugin_slug, $ynf_options);
    
    // remember to re-generate the feed
    ynf_generate_feed();
    
    die('<p>' . __('Settings saved.', $ynf_text_domain) . '</p>');
}

/**
* @desc     Displays the options form for the plugin
* @return   void
*/
function ynf_options_form()
{
    global $wpdb, $ynf_plugin_slug, $ynf_plugin_dir, $ynf_plugin_name, $ynf_text_domain,$ynf_plugin_url;
    
    $ynf_options = get_option($ynf_plugin_slug);
    if (!isset($ynf_options['excluded_categories']))
    {
        $ynf_options['excluded_categories'] = array();
    }
    
    $imgpath=$ynf_plugin_url.'/i';	
    $actionurl=$_SERVER['REQUEST_URI'];
 
    
?>
<script type="text/javascript" src="<?php echo $ynf_plugin_dir?>js/admin-onload.js"></script>
<div class="wrap" style="max-width:950px !important;">
    <div id="icon-options-general" class="icon32"><br /></div>
    <h2><?php _e("$ynf_plugin_name Options", $ynf_text_domain)?></h2>
    
    <div id="poststuff" style="margin-top:10px;">
	
	<div id="sideblock" style="float:right;width:220px;margin-left:10px;"> 
		 <h2>Information</h2>
		 <div id="dbx-content" style="text-decoration:none;">
		  <img src="<?php echo $imgpath; ?>/home.png"><a style="text-decoration:none;" href="http://www.prelovac.com/vladimir/wordpress-plugins/yahoo-news-feed"> Yahoo News Feed Home</a><br /><br />
			<img src="<?php echo $imgpath; ?>/rate.png"><a style="text-decoration:none;" href="http://wordpress.org/extend/plugins/yahoo-news-feed/"> Rate this plugin</a><br /><br />			 
			<img src="<?php echo $imgpath; ?>/help.png"><a style="text-decoration:none;" href="http://www.prelovac.com/vladimir/forum"> Support and Help</a><br />			 
			<p >
			<a style="text-decoration:none;" href="https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=2567254&lc=US"><img src="<?php echo $imgpath; ?>/paypal.gif"></a>			 
			</p><br />		 
			<img src="<?php echo $imgpath; ?>/more.png"><a style="text-decoration:none;" href="http://www.prelovac.com/vladimir/wordpress-plugins"> Cool WordPress Plugins</a><br /><br />
			<img src="<?php echo $imgpath; ?>/twit.png"><a style="text-decoration:none;" href="http://twitter.com/vprelovac"> Follow updates on Twitter</a><br /><br />			
			<img src="<?php echo $imgpath; ?>/idea.png"><a style="text-decoration:none;" href="http://www.prelovac.com/vladimir/services"> Need a WordPress Expert?</a> 
  </div>
 	</div>
   <div id="mainblock" style="width:710px">
	 
		<div class="dbx-content"> 
    
    <form action="index.php" method="post" class="ajax" autocomplete="off">
    	
        <div class="updated fade" id="result" style="display:none"></div>
        
        <p>
            <?php _e('Include at most', $ynf_text_domain)?>
            <input style="width: 45px" type="text" name="number_of_items" value="<?php echo $ynf_options['number_of_items'] ?>" />
            <?php _e('posts not older than', $ynf_text_domain)?>
            <input style="width: 45px" type="text" name="days" value="<?php echo $ynf_options['days'] ?>" />
            <?php _e('days', $ynf_text_domain)?>
        </p>
        <p>Select post categories you want to <em>exclude</em> from the feed:</p>
            <ul>
<?php
$args = array(
    'hide_empty'        => false,
    'hierarchical'      => 0,
);

$cats = get_categories($args); 
foreach ($cats as $cat)
{
    printf ('<li><label><input type="checkbox" name="excluded_categories[]" value="%s" %s> %s</label></li>',
        $cat->cat_ID, in_array($cat->cat_ID, $ynf_options['excluded_categories']) ? 'checked="checked"' : '', $cat->cat_name);
}
?>
            </ul>
            
            <p>The file is located at <a href="<?php echo get_settings('siteurl') ?>/yahoo-news.xml"><?php echo get_settings('siteurl') ?>/yahoo-news.xml</a></p><p>To submit the feed go to <a href="https://siteexplorer.search.yahoo.com/mysites">Yahoo SiteExplorer</a>, register your site and add a feed.</p>
            
        <p class="submit">
        <input type="hidden" value="<?php echo wp_create_nonce($ynf_plugin_slug)?>" name="_nonce" />
        <input type="hidden" name="ynf_action" value="options" />
        <input class="button-primary" name="submit" type="submit" value="<?php _e('Save Options', $ynf_text_domain)?>" />
        </p>
        <div id="loading" style="display:none"><img src="<?php echo $ynf_plugin_dir?>img/loading.gif" alt="<?php _e('Loading...', $ynf_text_domain)?>" /></div>
    </form>
  </div>
</div>
<h5>another quality plugin by <a href="http://www.prelovac.com/vladimir/">Vladimir Prelovac</a></h5>
</div>
<?php
}

/**
 * @desc	Adds the Options menu item
 * @return  void
 */
function ynf_menu_items()
{
    global $ynf_plugin_name;
	add_options_page($ynf_plugin_name, $ynf_plugin_name, 8, basename(__FILE__), 'ynf_options_form');
}

add_action('admin_menu', 'ynf_menu_items');

/**
* @desc     A small helper to log important data
* The log file can be found in the plugin directory under the name, erm, "log"
* @param    mixed   The data to log
* @return   void
*/
function ynf_write_log($val)
{
    if (is_array($val))
    {
        $val = print_r($val, 1);
    }
    
    if (is_object($val))
    {
        ob_start();
        var_dump($val);
        $val = ob_get_clean();
    }
    
    $handle = fopen(dirname(__FILE__) . '/log', 'a');
    fwrite($handle, $val . PHP_EOL);
    fclose($handle);
}