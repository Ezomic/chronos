<?php

return [

    'errors' => [
        'starts_at' => 'Dit tijdstip is niet meer beschikbaar. Kies een ander moment.',
        'name' => 'Vul je naam in (maximaal 100 tekens).',
        'email' => 'Vul een geldig e-mailadres in.',
        'phone' => 'Vul een geldig telefoonnummer in, of laat het leeg.',
        'note' => 'Houd de opmerking bij één regel van maximaal 300 tekens.',
        'timezone' => 'We konden je tijdzone niet lezen. Kies er een uit de lijst.',
        'website' => 'Er ging iets mis. Probeer het opnieuw.',
    ],

    'mail' => [
        'hello' => 'Hoi :name,',
        'confirmed' => [
            'subject' => 'Je kennismakingsgesprek is bevestigd',
            'intro' => 'Je :type staat gepland op:',
            'attached' => 'Een agenda-uitnodiging zit als bijlage bij deze mail.',
            'manage' => 'Bekijk of annuleer deze afspraak',
            'reschedule' => 'Past het toch niet? Annuleer de afspraak via bovenstaande link en plan een nieuw moment.',
        ],
        'received' => [
            'subject' => 'Nieuwe afspraak: :name',
            'intro' => ':name heeft een gesprek geboekt op:',
            'email' => 'E-mail',
            'phone' => 'Telefoon',
            'note' => 'Opmerking',
            'manage' => 'Bekijk of annuleer deze afspraak',
        ],
        'cancelled' => [
            'subject' => 'Afspraak geannuleerd: :name',
            'intro' => ':name heeft het gesprek geannuleerd op:',
            'freed' => 'De afspraak is uit je agenda gehaald en het tijdstip is weer vrij.',
        ],
    ],

    'ui' => [
        'heading' => 'Plan een kennismakingsgesprek',
        'duration' => ':minutes minuten',
        'intro' => 'Kies een moment dat jou past. Je krijgt een bevestiging per e-mail.',
        'timezone' => 'Tijden getoond in',
        'pick_day' => 'Kies een dag',
        'pick_time' => 'Kies een tijd',
        'no_slots' => 'Er zijn de komende weken geen vrije momenten. Kom later nog eens terug.',
        'details' => 'Je gegevens',
        'name' => 'Naam',
        'email' => 'E-mail',
        'phone' => 'Telefoonnummer (optioneel)',
        'note' => 'Waar wil je het over hebben? (optioneel)',
        'selected' => 'Jouw moment',
        'submit' => 'Dit moment boeken',
        'submitting' => 'Bezig met boeken...',
        'reload' => 'Toon de nieuwste tijden',
        'confirmed_heading' => 'Je afspraak staat',
        'confirmed_body' => 'Een bevestiging met agenda-uitnodiging is onderweg naar :email.',
        'cancel' => 'Deze afspraak annuleren',
        'cancel_hint' => 'Past het toch niet? Annuleer deze afspraak en plan een nieuw moment.',
        'cancelled_heading' => 'Afspraak geannuleerd',
        'cancelled_body' => 'Dit tijdstip is weer vrij.',
        'book_again' => 'Een ander moment plannen',
        'language' => 'English',
        'language_code' => 'en',
    ],

];
