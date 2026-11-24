<?php

return [

    'title' => 'Ro‘yxatdan o‘tish',

    'heading' => 'Hisob qaydnomasini ro‘yxatdan o‘tkazing',

    'actions' => [

        'login' => [
            'before' => 'yoki',
            'label' => 'o‘z hisob qaydnomangiz orqali kiring',
        ],

    ],

    'form' => [

        'email' => [
            'label' => 'Elektron pochta manzili',
        ],

        'name' => [
            'label' => 'Ism',
        ],

        'password' => [
            'label' => 'Parol',
            'validation_attribute' => 'password',
        ],

        'password_confirmation' => [
            'label' => 'Parolni tasdiqlash',
        ],

        'actions' => [

            'register' => [
                'label' => 'Ro‘yxatdan o‘tish',
            ],

        ],

    ],

    'notifications' => [

        'throttled' => [
            'title' => 'Ro‘yxatdan o‘tishga urinishlar juda ko‘p',
            'body' => 'Iltimos, :seconds soniyadan keyin qayta urinib ko‘ring.',
        ],

    ],

];
