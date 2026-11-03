{!! __('booking.mail.hello', ['name' => $name]) !!}

{!! __('booking.mail.confirmed.intro', ['type' => $type]) !!}

{!! $when !!}

{!! __('booking.mail.confirmed.attached') !!}

{!! __('booking.mail.confirmed.manage') !!}: {!! $manageUrl !!}

{!! __('booking.mail.confirmed.reschedule') !!}
