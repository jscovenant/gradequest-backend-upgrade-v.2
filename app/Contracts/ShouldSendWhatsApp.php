<?php

namespace App\Contracts;

interface ShouldSendWhatsApp
{
     /**
     * Return:
     * [
     *   'template' => string,
     *   'lang' => string,
     *   'params' => array
     * ]
     */
    public function toWhatsApp($notifiable): array;
}
