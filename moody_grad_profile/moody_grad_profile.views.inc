<?php

/**
 * Implements hook_views_data().
 */
function moody_grad_profile_views_data()
{
    $data['views']['grad_profile_header'] = [
        'title' => t('Grad Profile Links Header'),
        'help' => t('Provides a link the grad profile pages.'),
        'area' => [
            'id' => 'grad_profile_header',
        ],
    ];
    return $data;
}
