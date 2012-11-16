<?php

/**
 * Class with all the know-how about article lists creation and how its data is stored in the database.
 * All direct use of wpdb in the Arlima plugin should be placed in this class, at least as long as
 * the database communication is about getting data related to article lists.
 *
 * @todo: Remove deprecated functions when moving up to version 3.0
 * @package Arlima
 * @since 2.0
 */
class Arlima_ListFactory {

    const DB_VERSION = '1.7';

    /**
     * Default options for an article list
     * @var array
     */
    private $options = array(
            'previewtemplate' => 'article',
            'before_title' => '<h2>',
            'after_title' => '</h2>',
            'pagestopurge' => ''
        );

    /**
     * @var wpdb
     */
    private $wpdb;

    /**
     * @var Arlima_CacheManager
     */
    private $cache;

    /**
     * @param wpdb $db
     * @param null $cache
     */
    public function __construct($db = null, $cache = null) {
        $this->wpdb = $db === null ? $GLOBALS['wpdb'] : $db;
        $this->cache = $cache === null ? Arlima_CacheManager::loadInstance() : $cache;
    }

    /**
     * Creates a new article list
     * @param $title
     * @param $slug
     * @param array $options
     * @param int $max_length
     * @throws Exception
     * @return Arlima_List
     */
    public function createList($title, $slug, $options=array(), $max_length=50) {
        $options = array_merge($this->options, $options);

        $insert_data = array(
            time(),
            $title,
            $slug,
            $max_length,
            serialize( $options )
        );

        // Insert list data in DB
        $sql = 'INSERT INTO ' . $this->wpdb->prefix . 'arlima_articlelist
                (al_created, al_title, al_slug, al_maxlength, al_options)
                VALUES (%d, %s, %s, %d, %s)';

        $this->executeSQLQuery('query', $this->wpdb->prepare($sql, $insert_data));
        $id = $this->wpdb->insert_id;

        // Remove slug cache
        $cache = Arlima_CacheManager::loadInstance();
        $cache->delete('arlima_list_slugs');

        // Create list object
        $list = new Arlima_List(true, $id);
        $list->setCreated($insert_data[0]);
        $list->setMaxlength($max_length);
        $list->setOptions($options);
        $list->setSlug($slug);
        $list->setTitle($title);

        return $list;
    }

    /**
     * Will update name, slug and options of given list
     * @param Arlima_List $list
     * @throws Exception
     */
    public function updateListProperties($list) {
        $update_data = array(
            $list->getTitle(),
            $list->getSlug(),
            $list->getMaxlength(),
            serialize( $list->getOptions() ),
            (int)$list->id()
        );

        $sql = 'UPDATE ' . $this->wpdb->prefix . 'arlima_articlelist
                    SET al_title = %s, al_slug = %s, al_maxlength=%d, al_options = %s
                    WHERE al_id = %d ';

        $this->executeSQLQuery('query', $this->wpdb->prepare($sql, $update_data));

        // remove cache
        $this->cache->delete('arlima_list_props_'.$list->id());
    }

    /**
     * @param Arlima_List $list
     */
    public function deleteList($list) {

        // Get versions
        $version_data = $this->wpdb->get_results(sprintf(
                            "SELECT alv_id FROM %sarlima_articlelist_version WHERE alv_al_id=%d",
                            $this->wpdb->prefix,
                            (int)$list->id()
                        ));

        // Remove articles
        if( !empty($version_data) ) {
            foreach($version_data as $data) {
                $versions[] = $data->alv_id;
            }
            $this->executeSQLQuery('query', sprintf(
                        "DELETE FROM %sarlima_articlelist_article WHERE ala_alv_id in (%s)",
                        $this->wpdb->prefix,
                        implode(',', $versions)
                    ));
        }

        // Remove list properties
        $this->executeSQLQuery('query', 'DELETE FROM '.$this->wpdb->prefix.'arlima_articlelist WHERE al_id='.$list->id());

        // Remove versions
        $this->executeSQLQuery('query', 'DELETE FROM '.$this->wpdb->prefix.'arlima_articlelist_version WHERE alv_al_id='.$list->id() );

        // remove cache
        $this->cache->delete('arlima_list_props_'.$list->id());
        $this->cache->delete('arlima_list_articles_data_'.$list->id());
    }

    /**
     * @param Arlima_List $list
     * @param array $articles
     * @param int $user_id
     * @param bool $preview
     * @throws Exception
     */
    public function saveNewListVersion($list, $articles, $user_id, $preview = false) {

        if(!$list->exists())
            throw new Exception('You can not create a new version of a list that does not exist');
        if($list->isImported())
            throw new Exception('You can not save a new version of a list that is imported');

        $this->removeOldVersions($list);
        self::sanitizeList($list);

        // Create the new version
        $sql = $this->wpdb->prepare(
                "INSERT INTO " . $this->wpdb->prefix . "arlima_articlelist_version
                (alv_created, alv_al_id, alv_status, alv_user_id)
                VALUES (%d, %s, %d, %d)",
                    time(),
                    $list->id(),
                    $preview ? Arlima_List::STATUS_PREVIEW : Arlima_List::STATUS_PUBLISHED,
                    $user_id
                );

        $this->executeSQLQuery('query', $sql);
        $version_id = $this->wpdb->insert_id;

        // Save the articles of this verion of the list
        if( !empty($articles) ) {

            // Update possibly changed published date
            $post_id_map = array();
            foreach( $articles as $i => $article ) {
                if( !empty($article['post_id']) ) {
                    $post_id_map[$i] = $article['post_id'];
                }
            }

            if( !empty($post_id_map) ) {
                $sql = "SELECT post_date_gmt, ID FROM %sposts WHERE ID in (%s)";
                $sql = sprintf($sql, $this->wpdb->prefix, implode(',', $post_id_map));
                foreach($this->executeSQLQuery('get_results', $sql) as $row) {
                    foreach( array_keys($post_id_map, $row->ID) as $key ) {
                        $articles[$key]['publish_date'] = strtotime($row->post_date_gmt);
                    }
                }
            }

            $count = 0;
            foreach( $articles as $sort => $article ) {
                $this->saveArticle($version_id, $article, $sort, -1, $count);
                $count++;
                if( $count >= ( $list->getMaxlength()-1 ) )
                    break;
            }
        }

        if( !$preview )
            $this->cache->delete('arlima_list_articles_data_'.$list->id());
    }

    /**
     * @param int $version_id
     * @param array $article
     * @param mixed $sort,
     * @param int $parent[optional=-1]
     * @param int $offset
     */
    private function saveArticle($version_id, $article, $sort, $parent=-1, $offset) {

        if( !is_array($article['options']) )
            $article['options'] = array();
        if( !is_array($article['image_options']) )
            $article['image_options'] = array();

        $options = serialize( self::cleanArticleOptions($article[ 'options' ]) );
        $image_options = serialize( $article[ 'image_options' ] );

        $sql = $this->wpdb->prepare(
                    "INSERT INTO " . $this->wpdb->prefix . "arlima_articlelist_article
                    (ala_created, ala_publish_date, ala_alv_id, ala_post_id, ala_title,
                    ala_text, ala_sort, ala_title_fontsize, ala_url, ala_options,
                    ala_image, ala_image_options, ala_parent)
                    VALUES (%d, %d, %d, %d, %s, %s, %d, %d, %s, %s, %s, %s, %d)",
                    empty($article['created']) ? time():(int)$article['created'],
                    empty($article['publish_date']) ? time():(int)$article['publish_date'],
                    $version_id,
                    (int)$article[ 'post_id' ],
                    stripslashes( $article[ 'title' ] ),
                    stripslashes( $article[ 'text' ] ),
                    (int)$sort,
                    (int)$article[ 'title_fontsize' ],
                    $article[ 'url' ],
                    $options,
                    isset($article[ 'image' ]) ? $article[ 'image' ]:'',
                    $image_options,
                    (int)$parent
                );

        $this->executeSQLQuery('query', $sql);

        if( !empty( $article[ 'children' ] ) && is_array( $article[ 'children' ]) ) {
            foreach( $article[ 'children' ] as $sort => $child ) {
                $this->saveArticle($version_id, $child, $sort, $offset, false );
            }
        }
    }

    /**
     * Removes all preview versions created for this list and all old
     * published versions starting from $num_num_version_to_keep
     * @param Arlima_List $list
     * @param int $num_versions_to_keep
     */
    public function removeOldVersions($list, $num_versions_to_keep=10)
    {
        $old_versions = array();
        if ( $list->getStatus() == Arlima_List::STATUS_PUBLISHED ) {

            //fetch all versions older than the last 10
            $sql = $this->wpdb->prepare(
                        "SELECT alv_id FROM " . $this->wpdb->prefix . "arlima_articlelist_version
                        WHERE alv_al_id = %d AND alv_status = %d
                        ORDER BY alv_id DESC LIMIT %d, 10",
                        $list->id(),
                        Arlima_List::STATUS_PUBLISHED,
                        $num_versions_to_keep
                    );

            $old_versions = $this->executeSQLQuery('get_col', $sql);
        }

        // fetch all old previews
        $sql = $this->wpdb->prepare(
                    "SELECT alv_id FROM " . $this->wpdb->prefix . "arlima_articlelist_version
                    WHERE alv_al_id = %d AND alv_status = %d",
                    $list->id(),
                    Arlima_List::STATUS_PREVIEW
                );

        $old_previews = $this->executeSQLQuery('get_col', $sql);
        $versions_to_remove = array_merge($old_versions, $old_previews);

        // We have versions to remove
        if ( !empty($versions_to_remove) ) {

            // Remove articles belonging to versions that will be removed
            $this->executeSQLQuery(
                    'query',
                    sprintf(
                        "DELETE FROM " . $this->wpdb->prefix . "arlima_articlelist_article
                        WHERE ala_alv_id IN (%s)",
                        implode(',', $versions_to_remove)
                    )
                );

            // Delete the versions
            $this->executeSQLQuery(
                'query',
                sprintf(
                    "DELETE FROM " . $this->wpdb->prefix . "arlima_articlelist_version
                            WHERE alv_id IN (%s)",
                    implode(',', $versions_to_remove)
                )
            );
            return $sql;
        }
        return $sql;
    }

    /**
     * @param $id
     * @param bool|string|int $version
     * @return Arlima_List
     */
    public function loadList($id, $version=false) {
        $list = $this->queryList($id);
        if( !$list->exists() )
            return $list;

        // Get latest version (using cache)
        if( !$version ) {

            $article_data = $this->cache->get('arlima_list_articles_data_'.$id);
            if( !$article_data ) {

                $article_data = array();
                $version_data = $this->queryVersionData($id, false);
                $article_data['version'] = $version_data[0];
                $article_data['version_list'] = $version_data[1];
                $article_data['articles'] = $this->queryListArticles(false, true);

                $this->cache->set('arlima_list_articles_data_'.$id, $article_data);
            }

            if( !empty($article_data['version']) ) {
                $list->setStatus( Arlima_List::STATUS_PUBLISHED );
                $list->setArticles($article_data['articles']);
                $list->setVersions( $article_data['version_list'] );
                $list->setVersion( $article_data['version'] );
            }
        }

        // Preview version or specific version (no cache)
        else {
            list($version_data, $version_list) = $this->queryVersionData($id, $version);
            if( !empty($version_data) ) {
                $list->setVersion($version_data);
                $list->setVersions($version_list);
                $list->setArticles( $this->queryListArticles($version_data['id'], false) );
                $list->setStatus( $version === 'preview' ? Arlima_List::STATUS_PREVIEW : Arlima_List::STATUS_PUBLISHED);
            }
        }

        return $list;
    }

    /**
     * Calls a method on $wpdb and throws Exception if mysql error occurs
     * @param string $method
     * @param string $sql
     * @param bool $remove_prefix
     * @param bool $preserve_obj
     * @throws Exception
     * @return mixed
     */
    private function executeSQLQuery($method, $sql, $remove_prefix = false, $preserve_obj=false) {
        $obj = call_user_func(array($this->wpdb, $method), $sql);
        if( is_wp_error($obj) || $this->wpdb->last_error )
            throw new Exception($this->wpdb->last_error);

        return $remove_prefix !== false ? self::removePrefix($obj, $remove_prefix, $preserve_obj) : $obj;
    }

    /**
     * @param $list_id
     * @param $version
     * @return array
     */
    private function queryVersionData($list_id, $version) {

        $version_data_sql = "SELECT alv_id, alv_created, alv_status, alv_user_id FROM {$this->wpdb->prefix}arlima_articlelist_version";

        // latest preview version
        if( $version === 'preview' ) {
            $version_data_sql = $this->wpdb->prepare(
                        $version_data_sql." WHERE alv_al_id = %d AND alv_status = %d",
                        $list_id,
                        Arlima_List::STATUS_PREVIEW
                    );
        }

        // specific version
        elseif($version !== false) {
            $version_data_sql = $this->wpdb->prepare(
                        $version_data_sql." WHERE alv_id = %d",
                        $version
                    );
        }

        // latest none preview version
        else {
            $version_data_sql = $this->wpdb->prepare(
                        $version_data_sql." WHERE alv_al_id = %d AND alv_status = %d",
                        $list_id,
                        Arlima_List::STATUS_PUBLISHED
                    );
        }

        $version_data_sql .= ' ORDER BY alv_id DESC LIMIT 0,1';

        $version_list_sql = $this->wpdb->prepare (
            "SELECT alv_id FROM " . $this->wpdb->prefix . "arlima_articlelist_version
            WHERE alv_al_id = %d AND alv_status = %d
            ORDER BY alv_id DESC LIMIT 0,10",
            (int)$list_id,
            Arlima_List::STATUS_PUBLISHED
        );

        return array(
            $this->executeSQLQuery('get_row', $version_data_sql, 'alv_'),
            $this->executeSQLQuery('get_col', $version_list_sql)
         );
    }

    /**
     * @param int $id
     * @throws Exception
     * @return Arlima_List
     */
    private function queryList($id)
    {
        $list = $this->cache->get('arlima_list_props_'.$id);
        if( !$list ) {
            $sql = $this->wpdb->prepare(
                "SELECT * FROM " . $this->wpdb->prefix . "arlima_articlelist WHERE al_id = %d",
                (int)$id
            );

            $list_data = $this->executeSQLQuery('get_row', $sql, 'al_');

            if ( empty($list_data) ) {
                $list = new Arlima_List(false);
            } else {
                $list = new Arlima_List(true, $id);
                $list->setCreated($list_data['created']);
                $list->setTitle($list_data['title']);
                $list->setSlug($list_data['slug']);
                $list->setMaxlength($list_data['maxlength']);
                $list->setOptions(unserialize($list_data['options']));
                $this->cache->set('arlima_list_props_'.$id, $list);
            }
        }

        return $list;
    }

    /**
     * @see Arlima_ListFactory::loadList()
     * @param string $slug
     * @param bool $version
     * @return Arlima_List
     */
    public function loadListBySlug($slug, $version=false) {
        $id = $this->getListId($slug);
        if( $id )
            return $this->loadList($id, $version);

        return new Arlima_List(false);
    }

    /**
     * Load latest preview version of article list with given id.
     * @param int $id
     * @return Arlima_List
     */
    public function loadLatestPreview($id) {
        if( !is_numeric($id) ) {
            Arlima_Plugin::warnAboutUseOfDeprecatedFunction('Arlima_ListFactory::loadLatestPreview', 2.5, 'Should be called using list id as argument, not slug');
            $id = $this->getListId($id);
        }

        return $this->loadList($id, 'preview');
    }

    /**
     * @param $version
     * @param bool $exclude_future_posts
     * @return array
     */
    private function queryListArticles($version, $exclude_future_posts) {

        $sql = "SELECT ala_id, ala_created, ala_publish_date, ala_post_id, ala_title, ala_text,
                        ala_title_fontsize, ala_url, ala_options, ala_image, ala_image_options, ala_parent, ala_sort
                        FROM " . $this->wpdb->prefix . "arlima_articlelist_article %s ORDER BY ala_parent, ala_sort";

        $where = '';
        if($version)
            $where .= ' WHERE ala_alv_id='.intval($version);


        $articles = array();
        foreach($this->executeSQLQuery('get_results', sprintf($sql, $where) ) as $row) {

            if( $row->ala_options !== '' ) { // once upon a time this variable could be an empty string
                $row->ala_options = unserialize( $row->ala_options );
            } else {
                $row->ala_options = array();
            }

            if( $row->ala_image_options !== '' ) {
                $row->ala_image_options = unserialize( $row->ala_image_options );
            } else {
                $row->ala_image_options = array();
            }

            $row->children = array();

            if( $row->ala_parent == -1 ) {
                $articles[] = self::removePrefix( $row, 'ala_' );
            } else {
                $articles[ $row->ala_parent ]['children'][] = self::removePrefix( $row, 'ala_' );
            }
        }

        // Remove future posts
        if( $exclude_future_posts ) {
            foreach( $articles as $i => $article ) {
                if( $article['publish_date'] && ( $article['publish_date'] > time() ) ) {
                    unset( $articles[$i] );
                }
            }

            // Reset the numerical order of keys that might have been
            // mangled when removing future articles
            $articles = array_values( $articles );
        }

        return $articles;
    }

    /**
     * will return an array looking like array( stdClass(id => ... title => ... slug => ...) )
     * @return array
     */
    public function loadListSlugs() {
        $data = $this->cache->get('arlima_list_slugs');
        if(!is_array($data)) {
            $sql = 'SELECT al_id, al_title, al_slug
                    FROM ' . $this->wpdb->prefix . 'arlima_articlelist
                    ORDER BY al_title ASC';

            $data = $this->executeSQLQuery('get_results', $sql, 'al_', true);
            $this->cache->set('arlima_list_slugs', $data);
        }

        return $data;
    }

    /**
     * @param $slug
     * @return int|bool
     */
    public function getListId($slug) {
        foreach($this->loadListSlugs() as $data) {
            if($data->slug == $slug)
                return $data->id;
        }

        return false;
    }



    /* * * * * * * * * * * * * * * * * INSTALL / UNINSTALL  * * * * * * * * * * * * * * * * * */



    /**
     * Database installer for this plugin.
     * @static
     */
    public function install() {

        $table_name = $this->wpdb->prefix . "arlima_articlelist";
        if($this->wpdb->get_var("show tables like '$table_name'") != $table_name) {
            self::createDatabaseTables($this->wpdb);
            add_option("arlima_db_version", self::DB_VERSION);
        }

        $installed_ver = get_option( "arlima_db_version" );

        if( $installed_ver != self::DB_VERSION ) {
            self::createDatabaseTables($this->wpdb);
            update_option( "arlima_db_version", self::DB_VERSION );
        }
    }

    /**
     * Executes SQL queries that creates or updates the database tables
     * needed by this plugin
     *
     * @static
     * @param wpdb $wpdb
     */
    private static function createDatabaseTables($wpdb) {
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        $table_name = $wpdb->prefix . "arlima_articlelist";

        $sql = "CREATE TABLE " . $table_name . " (
        al_id mediumint(9) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        al_created bigint(11) DEFAULT '0' NOT NULL,
        al_title tinytext NOT NULL,
        al_slug varchar(50),
        al_options text,
        al_maxlength mediumint(9) DEFAULT '100' NOT NULL,
        UNIQUE KEY id (al_id),
        KEY created (al_created),
        KEY slug (al_slug)
        );";

        dbDelta($sql);

        $table_name = $wpdb->prefix . "arlima_articlelist_version";

        $sql = "CREATE TABLE " . $table_name . " (
        alv_id mediumint(9) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        alv_created bigint(11) DEFAULT '0' NOT NULL,
        alv_al_id mediumint(9) NOT NULL,
        alv_status tinyint(1) DEFAULT '1' NOT NULL,
        alv_user_id mediumint(9) NOT NULL,
        UNIQUE KEY id (alv_id),
        KEY created (alv_created),
        KEY alid (alv_al_id),
        KEY alid_created (alv_al_id, alv_created)
        );";

        dbDelta($sql);

        $table_name = $wpdb->prefix . "arlima_articlelist_article";

        $sql = "CREATE TABLE " . $table_name . " (
        ala_id mediumint(9) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        ala_created bigint(11) DEFAULT '0' NOT NULL,
        ala_publish_date bigint(11) DEFAULT '0' NOT NULL,
        ala_alv_id mediumint(9) NOT NULL,
        ala_post_id mediumint(9) DEFAULT '-1' NOT NULL,
        ala_title varchar(255),
        ala_text text,
        ala_sort mediumint(9) DEFAULT '100' NOT NULL,
        ala_title_fontsize tinyint(1) DEFAULT '24' NOT NULL,
        ala_url varchar(255),
        ala_options text,
        ala_image varchar(255),
        ala_image_options text,
        ala_parent mediumint(9) DEFAULT '-1' NOT NULL,
        UNIQUE KEY id (ala_id),
        KEY created (ala_created),
        KEY alvid (ala_alv_id),
        KEY alvid_created (ala_alv_id, ala_created),
        KEY alvid_sort (ala_alv_id, ala_sort),
        KEY alvid_sort_created (ala_alv_id, ala_sort, ala_created),
        KEY postid (ala_post_id),
        KEY postpublishdate (ala_publish_date)
        );";

        dbDelta($sql);
    }

    public static function databaseUpdates($version) {
        /* @var wpdb $wpdb */
        global $wpdb;
        if($version < 2.2) {
            $wpdb->query('ALTER TABLE '.$wpdb->prefix.'arlima_articlelist_article ADD ala_publish_date bigint(11) NOT NULL DEFAULT \'0\'');
            $wpdb->query('ALTER TABLE '.$wpdb->prefix.'arlima_articlelist_article ADD INDEX `postpublishdate` (ala_publish_date)');
        }
        elseif($version < 2.5) {
            $wpdb->query('ALTER TABLE '.$wpdb->prefix.'arlima_articlelist_article DROP ala_status');
            $wpdb->query('ALTER TABLE '.$wpdb->prefix.'arlima_articlelist DROP al_status');
        }
    }

    /**
     * Removes the database tables created when plugin was installed
     * @static
     */
    public function uninstall() {
        $this->wpdb->query('DROP TABLE IF EXISTS '.$this->wpdb->prefix.'arlima_articlelist');
        $this->wpdb->query('DROP TABLE IF EXISTS '.$this->wpdb->prefix.'arlima_articlelist_version');
        $this->wpdb->query('DROP TABLE IF EXISTS '.$this->wpdb->prefix.'arlima_articlelist_article');
    }




    /* * * * * * * * * * * * * * * * * STATIC UTILITY FUNCTIONS  * * * * * * * * * * * * * * * * * */




    /**
     * Removes redundant information from options array
     * @param array $options
     * @return array
     */
    private static function cleanArticleOptions(array $options) {
        if( empty($options['streamer']) ) {
            unset($options['streamer_type']);
            unset($options['streamer_content']);
            unset($options['streamer_color']);
            unset($options['streamer_image']);
        }

        if( empty($options['sticky']) ) {
            unset($options['sticky_pos']);
            unset($options['sticky_interval']);
        }

        return $options;
    }

    /**
     * Updates publish date for all arlima articles related to given post and clears the cache
     * of the lists where they appear
     * @static
     * @param stdClass $post
     */
    public static function updateArlimaArticleData($post) {
        if($post && $post->post_type == 'post') {
            /* @var wpdb $wpdb */
            global $wpdb;

            $date = strtotime($post->post_date_gmt);
            $prep_statement = $wpdb->prepare('UPDATE '.$wpdb->prefix.'arlima_articlelist_article SET ala_publish_date=%d WHERE ala_post_id=%d AND ala_publish_date != %d', $date, (int)$post->ID, $date);
            $wpdb->query($prep_statement);

            // Clear list cache
            if($wpdb->rows_affected > 0) {
                /* Get id of lists that has this post, could probably be done in a better way... */
                $sql = 'SELECT DISTINCT(alv_al_id)
                        FROM '.$wpdb->prefix.'arlima_articlelist_version
                        WHERE alv_id IN (
                                SELECT DISTINCT(ala_alv_id)
                                FROM '.$wpdb->prefix.'arlima_articlelist_article
                                WHERE ala_post_id=%d
                            )';

                $ids = $wpdb->get_results( $wpdb->prepare($sql, (int)$post->ID) );
                $cache = Arlima_CacheManager::loadInstance();
                foreach($ids as $id) {
                    $cache_id = 'arlima_articles_id_'.$id->alv_al_id;
                    $found = $cache->delete($cache_id);
                }
            }
        }
    }

    /**
     * @static
     * @param Arlima_List $list
     * @return array
     */
    protected static function sanitizeList( &$list ) {

        $list->setTitle( stripslashes($list->getTitle()) );
        $list->setSlug( sanitize_title(stripslashes($list->getSlug())) );
        $list->setOptions( array_map( 'stripslashes_deep', self::sanitizeListOptions( $list->getOptions() )) );

        if( !is_numeric($list->getMaxlength()) )
            $list->setMaxlength( 50 );
    }

    /**
     * @static
     * @param array $options
     * @return array
     */
    protected static function sanitizeListOptions($options) {
        $default_options = array(
            'previewpage' => '/',
            'previewtemplate' => 'article',
            'before_title' => '<h2>',
            'after_title' => '</h2>',
            'pagestopurge' => ''
        );

        // Override default options
        foreach($default_options as $name => $val) {
            if(empty($options[$name]))
                $options[$name] = $val;
        }

        // Remove options that does not exist
        $opt_names = array_keys($options);
        foreach($opt_names as $name) {
            if( !isset($default_options[$name]) )
                unset($options[$name]);
        }

        return $options;
    }

    /**
     * The article data is in fact created with javascript in front-end so you can't
     * see this function as the sole creator of article objects. For that reason it might be
     * good to take look at this function once in a while, making sure it generates a similar object
     * as generated with javascript in front-end.
     *
     * @static
     * @param array $override[optional=array()]
     * @return array
     */
    public static function createArticleDataArray($override=array()) {
        $options = array(
            'pre_title' => '',
            'streamer_color' => '',
            'streamer_content' => '',
            'streamer_image' => '',
            'streamer_type' => 'extra',
            'hiderelated' => false,
            'template' => '',
            'format' => ''
        );

        $data = array(
            'children' => array(),
            'id' => 0,
            'image' => '',
            'image_options' => array(),
            'options' => $options,
            'post_id' => 0,
            'status' => 1,
            'text' => '',
            'title' => 'Unknown',
            'title_fontsize' => 24,
            'url' => '',
            'created' => 0,
            'publish_date' => 0
            );

        foreach($override as $key => $val) {
            if($key == 'children') {
                if(!is_array($val))
                    $val = array();
                foreach($val as $sub_art_key => $sub_art_val)
                    $val[$sub_art_key] = self::createArticleDataArray($sub_art_val);
            }
            elseif($key == 'options') {
                if(is_array($val)) {
                    foreach($val as $opt_key => $opt_val) {
                        $options[$opt_key] = $opt_val;
                    }
                }
                $val = $options;
            }

            $data[$key] = $val;
        }

        return $data;
    }



    /**
     * Remove prefix from array keys, will also turn stdClass objects to arrays unless
     * $preserve_std_objects is set to true
     * @static
     * @param array $array
     * @param string $prefix
     * @param bool $preserve_std_objects[optional=false]
     * @return array
     */
    protected static function removePrefix($array = array(), $prefix, $preserve_std_objects=false) {
        $convert_to_std = $preserve_std_objects && $array instanceof stdClass;
        $new_array = array();
        $prefix_len = strlen($prefix);
        if($array) {
            foreach ( $array as $key => $value ) {
                $newkey = $key;
                if(substr($key, 0, $prefix_len) == $prefix)
                    $newkey = substr($key, $prefix_len);
                if(is_array($value) || $value instanceof stdClass)
                    $value = self::removePrefix($value, $prefix, $preserve_std_objects);
                $new_array[$newkey] = $value;
            }
        }
        return $convert_to_std ? (object)$new_array:$new_array;
    }


    /* * * * * * * * * * * * * * * * * DEPRECATED FUNCTIONS  * * * * * * * * * * * * * * * * * */



}