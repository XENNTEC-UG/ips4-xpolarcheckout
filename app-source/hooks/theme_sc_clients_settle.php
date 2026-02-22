//<?php

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !\defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
    exit;
}

class xpolarcheckout_hook_theme_sc_clients_settle extends _HOOK_CLASS_
{
    /* !Hook Data - DO NOT REMOVE */
    public static function hookData(): array
    {
        return parent::hookData();
    }
    /* End Hook Data */
}