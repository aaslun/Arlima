<?php
/**
 * @var Arlima_WP_AbstractAdminPage $this
 */

$arlima_plugin = $this->getPlugin();
$settings = $arlima_plugin->loadSettings();

$import_manager = new Arlima_ImportManager($arlima_plugin);
$list_repo = new Arlima_ListRepository();
$sys = Arlima_CMSFacade::load();

if( isset($_POST['settings']) ) {

    // Save some settings
    $settings = array_merge($settings, $_POST['settings']);
    if( !isset($_POST['settings']['streamer_colors']) )
        $settings['streamer_colors'] = array();

    $arlima_plugin->saveSettings($settings);

    // Save approved for export
    $approved = empty($_POST['approved']) ? array() : $_POST['approved'];
    $settings['available_export'] = $approved;
    $arlima_plugin->saveSettings($settings);

    // Remove imported lists
    if( !empty($_POST['remove_imported']) ) {
        foreach($_POST['remove_imported'] as $remove) {
            $sys->removeImportedList($remove);
        }
    }

    $message = __('Settings was successfully updated', 'arlima');
}


// Create a list of our arlima lists sorted so that those lists
// approved for export comes first. Also find out from which
// page the approved lists can be exported
$export_manager = new Arlima_ExportManager(empty($settings['available_export']) ? array():$settings['available_export']);
$lists_sorted = array();
$lists = $list_repo->loadListSlugs();
$has_exportable_list = false;
foreach($lists as &$list_data) {
    if($export_manager->isAvailableForExport($list_data->id)) {
        $has_exportable_list = true;

        // Monkey patch the list object
        $list_data->approved = true;
        $list_data->export_page = false;
        $pages = $sys->loadRelatedPages( $list_repo->load($list_data->id) );

        if(!empty($pages)) { // monkey patch from which page list can be exported
            $list_data->export_page = rtrim(get_permalink($pages[0]->ID),'/') .'/'.Arlima_WP_Plugin::EXPORT_FEED_NAME.'/';
        }

        array_unshift($lists_sorted, $list_data);
    }
    else {
        $list_data->approved = false;
        array_push($lists_sorted, $list_data);
    }
}

/**
 * @param Arlima_WP_Plugin $arlima_plugin
 * @param string $setting
 */
function output_yesorno_select($arlima_plugin, $setting) {
    $val = $arlima_plugin->getSetting($setting);
    ?>
    <select name="settings[<?php echo $setting ?>]">
        <option value="1"<?php echo !empty($val) ? ' selected="selected"':'' ?>>
            <?php _e('Yes', 'arlima') ?>
        </option>
        <option value="0"<?php echo empty($val) ? ' selected="selected"':'' ?>>
            <?php _e('No', 'arlima') ?>
        </option>
    </select>
<?php }

if( isset($message) ): ?>
    <div id="setting-error-settings_updated" class="updated settings-error success">
        <p><strong><?php echo $message; ?></strong></p>
    </div>
<?php endif; ?>
<div id="arlima-settings-page" style="padding-top: 22px">

    <form action="admin.php?page=arlima-settings" method="post">

        <p>
            <strong>Version: </strong> <?php echo $arlima_plugin->getSetting('install_version') ?>
        </p>

        <div class="arlima-postbox">
            <h3><?php _e('Streamer Colors', 'arlima') ?></h3>
            <div class="inside">
                <table>
                    <tr>
                        <td>
                            <?php _e('Use this tool to define colors that should be available as background colors for your', 'arlima') ?>
                            <a href="https://github.com/victorjonsson/Arlima/wiki/Custom-streamers" target="_blank">&quot;streamers&quot;</a>.
                        </td>
                        <td>
                            <input type="color" id="streamer-color" placeholder="FF0000" />
                            <input type="button" value="<?php _e('Add', 'arlima') ?>" id="streamer-button" class="button" />
                            <div id="streamer-wrapper" data-colors="<?php if( !empty($settings['streamer_colors']) ) echo implode(',', $settings['streamer_colors']) ?>"></div>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <?php if( Arlima_WP_Plugin::supportsImageEditor() ): ?>
            <div class="arlima-postbox">
                <h3><?php _e('Image Quality', 'arlima') ?></h3>
                <div class="inside">
                    <table>
                        <tr>
                            <td>
                                <?php _e('The quality of image versions generated by Arlima.','arlima') ?>
                            </td>
                            <td>
                                <select name="settings[image_quality]">
                                    <?php for($i=70; $i < 101; $i++): ?>
                                        <option value="<?php echo $i ?>"<?php echo $settings['image_quality'] == $i ? ' selected="selected"':'' ?>>
                                            <?php echo $i ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <div class="arlima-postbox">
            <h3><?php _e('Export', 'arlima') ?></h3>
            <div class="inside">
                <table>
                    <tr>
                        <td>
                            <?php _e('Choose which article lists that should be available for export', 'arlima') ?>
                        </td>
                        <td style="width:400px">
                            <input type="hidden" name="type" value="export" />
                            <div id="export-list" style="width: 100%; max-width: none">
                                <?php $i=0; foreach($lists_sorted as $list_data): $i++; ?>
                                    <p>
                                        <label for="list<?php echo $i; ?>">
                                            <input id="list<?php echo $i; ?>" type="checkbox" name="approved[]" value="<?php echo $list_data->id ?>" <?php if($list_data->approved) echo ' checked="checked"'; ?> />
                                            <strong><?php echo $list_data->title ?></strong>
                                        </label>
                                        <?php if($list_data->approved): ?>
                                            <span class="gray-small">
                                            <?php if($list_data->export_page): ?>
                                                    <?php _e('URL', 'arlima') ?>: <a href="<?php echo $list_data->export_page ?>" target="_blank"><?php echo $list_data->export_page ?></a>
                                                <?php else:
                                                    // todo: Translate
                                                    echo sprintf(__('This list is not related to any page!', 'arlima'), '&quot;'.$list_data->slug.'&quot;');
                                                endif; ?>
                                        </span>
                                        <?php endif; ?>
                                    </p>
                                <?php endforeach; ?>
                            </div>
                            <?php if($has_exportable_list): ?>
                                <p>
                                    <em class="gray-small">(<?php _e('You can export your lists in RSS format by adding ?format=rss to the URL.', 'arlima') ?>)</em>
                                </p>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <div class="arlima-postbox">
            <h3><?php _e('Import', 'arlima') ?></h3>
            <div class="inside">
                <table>
                    <tr>
                        <td>
                            <?php _e('With this tool you can import RSS-feeds and articles lists from remote websites. Once a list is imported it will be available in the list manager.', 'arlima') ?>
                        </td>
                        <td>
                            <input type="text" id="import-url" placeholder="http://...." style="width:140px" />
                            <input type="button" value="<?php _e('Import list', 'arlima') ?>" class="button-secondary action" onclick="importExternalList(jQuery('#import-url'), jQuery('#imported-lists'));" />
                        </td>
                    </tr>
                    <tr>
                        <td colspan="2">
                            <div id="imported-lists">
                                <?php foreach($import_manager->getImportedLists() as $list_data)
                                    Arlima_ImportManager::displayImportedList($list_data['url'], $list_data['title']); ?>
                            </div>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <div class="arlima-postbox">
            <h3><?php _e('Permissions', 'arlima') ?></h3>
            <div class="inside">
                <table>
                    <tr>
                        <td>
                            <?php _e('Allow editors to create section dividers', 'arlima') ?>
                        </td>
                        <td>
                            <label>
                                <?php output_yesorno_select($arlima_plugin, 'editor_sections'); ?>
                            </label>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <div class="arlima-postbox">
            <h3><?php _e('Newsbill settings', 'arlima') ?></h3>
            <div class="inside">
                <table>
                    <tr>
                        <td>
                            <?php _e('Replace font size slider with newsbill tag', 'arlima') ?>
                        </td>
                        <td>
                            <label>
                                <?php output_yesorno_select($arlima_plugin, 'newsbill_tag'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <td>
                            <?php _e('Streamer pre-text', 'arlima') ?>
                        </td>
                        <td>
                            <label>
                                <?php output_yesorno_select($arlima_plugin, 'streamer_pre'); ?>
                            </label>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <?php do_action('arlima_settings_page', $settings); ?>

        <p>
            <input type="submit" name="send" value="<?php _e('Save settings', 'arlima') ?>" class="button-primary" />
        </p>

    </form>

</div>