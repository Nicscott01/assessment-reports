<?php

namespace AssessmentReports;

class Post_Type
{
    public const POST_TYPE = 'assessment_report';
    public const GROUP_TAXONOMY = 'assessment_report_group';

    public function __construct()
    {
        add_action('init', [$this, 'register_post_type']);
        add_action('init', [$this, 'register_group_taxonomy']);
    }

    public function register_post_type()
    {
        $labels = [
            'name'                  => __('Reports', 'assessment-reports'),
            'singular_name'         => __('Report', 'assessment-reports'),
            'add_new'               => __('Add New Report', 'assessment-reports'),
            'add_new_item'          => __('Add New Report', 'assessment-reports'),
            'edit_item'             => __('Edit Report', 'assessment-reports'),
            'new_item'              => __('New Report', 'assessment-reports'),
            'view_item'             => __('View Report', 'assessment-reports'),
            'view_items'            => __('View Reports', 'assessment-reports'),
            'search_items'          => __('Search Reports', 'assessment-reports'),
            'not_found'             => __('No reports found', 'assessment-reports'),
            'not_found_in_trash'    => __('No reports found in Trash', 'assessment-reports'),
            'all_items'             => __('All Reports', 'assessment-reports'),
            'archives'              => __('Report Archives', 'assessment-reports'),
            'attributes'            => __('Report Attributes', 'assessment-reports'),
            'insert_into_item'      => __('Insert into report', 'assessment-reports'),
            'uploaded_to_this_item' => __('Uploaded to this report', 'assessment-reports'),
            'filter_items_list'     => __('Filter reports list', 'assessment-reports'),
            'items_list_navigation' => __('Reports list navigation', 'assessment-reports'),
            'items_list'            => __('Reports list', 'assessment-reports'),
        ];

        register_post_type(self::POST_TYPE, [
            'labels'             => $labels,
            'public'             => true,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'capability_type'    => 'post',
            'hierarchical'       => true,
            'supports'           => ['title', 'editor', 'page-attributes'],
            'has_archive'        => false,
            'rewrite'            => [
                'slug' => 'reports',
                'with_front' => false,
            ],
            'menu_icon'          => 'dashicons-analytics',
        ]);
    }

    public function register_group_taxonomy()
    {
        $labels = [
            'name'                       => __('Report Groups', 'assessment-reports'),
            'singular_name'              => __('Report Group', 'assessment-reports'),
            'search_items'               => __('Search Report Groups', 'assessment-reports'),
            'popular_items'              => __('Popular Report Groups', 'assessment-reports'),
            'all_items'                  => __('All Report Groups', 'assessment-reports'),
            'edit_item'                  => __('Edit Report Group', 'assessment-reports'),
            'view_item'                  => __('View Report Group', 'assessment-reports'),
            'update_item'                => __('Update Report Group', 'assessment-reports'),
            'add_new_item'               => __('Add New Report Group', 'assessment-reports'),
            'new_item_name'              => __('New Report Group Name', 'assessment-reports'),
            'separate_items_with_commas' => __('Separate report groups with commas', 'assessment-reports'),
            'add_or_remove_items'        => __('Add or remove report groups', 'assessment-reports'),
            'choose_from_most_used'      => __('Choose from the most used report groups', 'assessment-reports'),
            'not_found'                  => __('No report groups found', 'assessment-reports'),
            'back_to_items'              => __('Back to report groups', 'assessment-reports'),
        ];

        register_taxonomy(self::GROUP_TAXONOMY, self::POST_TYPE, [
            'labels'            => $labels,
            'public'            => false,
            'show_ui'           => true,
            'show_admin_column' => true,
            'show_in_quick_edit' => true,
            'show_tagcloud'     => false,
            'show_in_rest'      => true,
            'hierarchical'      => false,
            'rewrite'           => false,
        ]);
    }
}
