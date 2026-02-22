//<?php

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !\defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
    exit;
}

class xpolarcheckout_hook_invoiceViewHook extends _HOOK_CLASS_
{
    /**
     * Keep default invoice rendering during provider migration.
     *
     * @return void
     */
    public function view()
    {
        parent::view();
    }
}