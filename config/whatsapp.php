<?php

return [
    // Price you charge (or cost you want to deduct) PER WhatsApp send (MVP).
    // Later you can refine to "per conversation".
    'fee_reminder_cost' => (float) env('WHATSAPP_FEE_REMINDER_COST', 10.00), // e.g ₦10
];