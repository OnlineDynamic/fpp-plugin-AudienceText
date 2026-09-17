<?php
// Sidebar entry. Without one of these the setup page is reachable only by
// typing the URL, which is how plugin config pages get forgotten about.
//
// The audience-facing page is deliberately NOT listed here: it lives outside
// the FPP web root at /audience (see scripts/apache-audience.conf) precisely so
// that it is not part of the password-protected UI.

$menuEntries = Array(
        Array(
                'text' => '<i class="fas fa-comment-dots"></i> Audience Text',
                'type' => 'content',
                'page' => 'plugin_setup.php',
                'wrap' => 1
        )
);

##############################################################################
# Display the menu entries for this plugin
if (isset($menu) && isset($plugin)) {
        foreach ($menuEntries as $entry)
        {
                if ($entry['type'] != $menu)
                        continue;

                if (preg_match('/^http.?:\/\//', $entry['page']))
                {
                        printf("<li><a href='%s' target='_blank'>%s</a></li>\n",
                                $entry['page'], $entry['text']);
                }
                else
                {
                        $nopage = '';
                        if (isset($entry['wrap']) && ($entry['wrap'] == 0))
                                $nopage = '&nopage=1';

                        printf("<li><a href='plugin.php?plugin=%s&page=%s%s'>%s</a></li>\n",
                                $plugin, $entry['page'], $nopage, $entry['text']);
                }
        }
}
?>
