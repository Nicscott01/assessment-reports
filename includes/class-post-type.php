<?php

namespace AssessmentReports;

class Post_Type
{
    public const POST_TYPE = 'assessment_report';

    public function __construct()
    {
        add_action('init', [$this, 'register_post_type']);
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
}
