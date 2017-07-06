<?php

function data() {

    $data['users'] = [
        'singular' => 'user',
        'columns' => [
            [
                'name' => 'id',
                'type' => 'increments',
            ],
            [
                'name' => 'name',
                'type' => 'string',
            ],
            [
                'name' => 'email',
                'type' => 'string',
                'modifiers' => [
                    'unique' => '',
                ],
            ],
            [
                'name' => 'password',
                'type' => 'string',
            ],
            [
                'type' => 'rememberToken',
            ],
            [
                'type' => 'timestamps',
            ],
            [
                'type' => 'softDeletes',
            ],
        ],
    ];

    $data['profiles'] = [
        'singular' => 'profile',
        'columns' => [
            [
                'name' => 'id',
                'type' => 'increments',
            ],
            [
                'name' => 'user_id',
                'type' => 'integer',
                'modifiers' => [
                    'unsigned' => '',
                ],
            ],
            [
                'name' => 'phone',
                'type' => 'string',
                'arguments' => [30],
            ],
            [
                'name' => 'gender',
                'type' => 'string',
                'arguments' => [1],
            ],
        ],
        'indexes' => [
            [
                'type' => 'unique',
                'columns' => 'phone',
            ]
        ],
    ];

    $data['posts'] = [
        'singular' => 'post',
        'columns' => [
            [
                'name' => 'id',
                'type' => 'increments',
            ],
            [
                'name' => 'type',
                'type' => 'string',
            ],
            [
                'name' => 'user_id',
                'type' => 'integer',
                'modifiers' => [
                    'unsigned' => '',
                ],
            ],
            [
                'name' => 'amount',
                'type' => 'float',
                'arguments' => [8, 2],
            ],
            [
                'type' => 'timestamps',
            ],
            [
                'type' => 'softDeletes',
            ],
        ],
    ];

    $data['roles'] = [
        'singular' => 'role',
        'columns' => [
            [
                'name' => 'id',
                'type' => 'increments',
            ],
            [
                'name' => 'name',
                'type' => 'string',
            ],
        ],
    ];

    $data['role_user'] = [
        'singular' => 'role_user',
        'columns' => [
            [
                'name' => 'role_id',
                'type' => 'integer',
                'modifiers' => [
                    'unsigned' => '',
                ],
            ],
            [
                'name' => 'user_id',
                'type' => 'integer',
                'modifiers' => [
                    'unsigned' => '',
                ],
            ],
        ],
    ];

    return $data;

}
