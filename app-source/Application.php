<?php
/**
 * @brief		X Polar Checkout Gateway Application Class
 * @author		<a href='https://xenntec.com/'>XENNTEC UG</a>
 * @copyright	(c) 2023 XENNTEC UG
 * @package		Invision Community
 * @subpackage	X Polar Checkout Gateway
 * @since		16 Jan 2023
 * @version		
 */
 
namespace IPS\xpolarcheckout;

/**
 * X Polar Checkout Gateway Application Class
 */
class _Application extends \IPS\Application
{
    /**
     * ACP Menu — inject XENNTEC accordion CSS+JS
     *
     * @return	array
     */
    public function acpMenu()
    {
        $menu = parent::acpMenu();

        if ( \IPS\Dispatcher::hasInstance() AND \IPS\Dispatcher::i() instanceof \IPS\Dispatcher\Admin AND \mb_strpos( \IPS\Output::i()->headCss, '/* xenntec-accordion */' ) === FALSE )
        {
            \IPS\Output::i()->headCss .= <<<'XCSS'
/* xenntec-accordion */
[data-tab="tab_xenntec"] [data-menuKey] > ul { display: none; }
[data-tab="tab_xenntec"] [data-menuKey].xenntec-open > ul { display: block; }
[data-tab="tab_xenntec"] [data-menuKey] > h3 { cursor: pointer; position: relative; padding-right: 28px; user-select: none; }
[data-tab="tab_xenntec"] [data-menuKey] > h3::after { content: '\25B8'; position: absolute; right: 4px; top: 50%; transform: translateY(-50%); font-size: 11px; opacity: 0.6; transition: transform 0.15s ease; }
[data-tab="tab_xenntec"] [data-menuKey].xenntec-open > h3::after { transform: translateY(-50%) rotate(90deg); }
[data-tab="tab_xenntec"] > a .acpAppList_icon i.fa { display: none; }
[data-tab="tab_xenntec"] > a .acpAppList_icon { background: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 128 128'%3E%3Cdefs%3E%3ClinearGradient id='xg' x1='0' y1='0' x2='1' y2='1'%3E%3Cstop offset='0%25' stop-color='%235ce7ff'/%3E%3Cstop offset='50%25' stop-color='%236d72ff'/%3E%3Cstop offset='100%25' stop-color='%23ff66d6'/%3E%3C/linearGradient%3E%3C/defs%3E%3Cg fill='none' stroke='url(%23xg)' stroke-linecap='round' stroke-width='12'%3E%3Cline x1='36' y1='36' x2='92' y2='92'/%3E%3Cline x1='92' y1='36' x2='36' y2='92'/%3E%3C/g%3E%3C/svg%3E") center/35px 35px no-repeat; min-width: 35px; min-height: 35px; }
[data-tab="tab_xenntec"] [data-menuKey].xenntec-app-sep { border-top: 1px solid rgba(0,0,0,0.1); margin-top: 6px; padding-top: 6px; }
XCSS;

            \IPS\Output::i()->headJs .= <<<'XJS'
(function(){if(window.__xntcAcc)return;window.__xntcAcc=1;document.addEventListener('DOMContentLoaded',function(){var t=document.querySelector('[data-tab="tab_xenntec"]');if(!t)return;var s={};try{s=JSON.parse(localStorage.getItem('xenntec_acp_accordion')||'{}');}catch(e){}var p='';t.querySelectorAll('[data-menuKey]').forEach(function(g){var k=g.getAttribute('data-menuKey');var a=k.substring(0,k.lastIndexOf('_'));if(p&&a!==p)g.classList.add('xenntec-app-sep');p=a;var h=g.querySelector('h3');if(s[k]){g.classList.add('xenntec-open');}if(h){h.addEventListener('click',function(e){e.preventDefault();e.stopPropagation();g.classList.toggle('xenntec-open');var st={};t.querySelectorAll('[data-menuKey]').forEach(function(x){st[x.getAttribute('data-menuKey')]=x.classList.contains('xenntec-open');});try{localStorage.setItem('xenntec_acp_accordion',JSON.stringify(st));}catch(e){}});}});});})();
XJS;
        }

        return $menu;
    }
}
