<?php

return [

    'errors' => [
        'starts_at' => 'That time is no longer available. Please pick another one.',
        'name' => 'Please enter your name (up to 100 characters).',
        'email' => 'Please enter a valid email address.',
        'note' => 'Keep the note to one line of at most 300 characters.',
        'timezone' => 'We could not read your time zone. Please pick one from the list.',
        'website' => 'Something went wrong. Please try again.',
    ],

    'mail' => [
        'hello' => 'Hi :name,',
        'confirmed' => [
            'subject' => 'Your intro call is confirmed',
            'intro' => 'Your :type is booked for:',
            'attached' => 'A calendar invite is attached.',
            'manage' => 'View or cancel this booking',
            'reschedule' => 'Need another time? Cancel this booking with the link above and book a new one.',
        ],
        'received' => [
            'subject' => 'New booking: :name',
            'intro' => ':name booked a call for:',
            'email' => 'Email',
            'note' => 'Note',
            'manage' => 'View or cancel this booking',
        ],
        'cancelled' => [
            'subject' => 'Booking cancelled: :name',
            'intro' => ':name cancelled their call on:',
            'freed' => 'The event is removed from your calendar and the time is open again.',
        ],
    ],

    'ui' => [
        'heading' => 'Book an intro call',
        'duration' => ':minutes minutes',
        'intro' => 'Pick a time that suits you. You will get a confirmation by email.',
        'timezone' => 'Times shown in',
        'pick_day' => 'Choose a day',
        'pick_time' => 'Choose a time',
        'no_slots' => 'There are no free times in the coming weeks. Please check back later.',
        'details' => 'Your details',
        'name' => 'Name',
        'email' => 'Email',
        'note' => 'What would you like to talk about? (optional)',
        'selected' => 'Your time',
        'submit' => 'Book this time',
        'submitting' => 'Booking...',
        'reload' => 'Show the latest times',
        'confirmed_heading' => 'You are booked',
        'confirmed_body' => 'A confirmation with a calendar invite is on its way to :email.',
        'cancel' => 'Cancel this booking',
        'cancel_hint' => 'Need another time? Cancel this booking and book a new one.',
        'cancelled_heading' => 'Booking cancelled',
        'cancelled_body' => 'This time is free again.',
        'book_again' => 'Book another time',
        'language' => 'Nederlands',
        'language_code' => 'nl',
    ],

];
