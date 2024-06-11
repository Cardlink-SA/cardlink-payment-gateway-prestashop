<!doctype html>
<html>

<head>
    <meta charset="utf-8">
    <title>{l s='Redirecting you to Cardlink payment gateway...' mod='cardlink_checkout'}</title>
    <meta name="robots" content="noindex, nofollow" />
    <link rel="stylesheet" type="text/css" href="{$css_url}" media="all" />
</head>

<body id="cardlink_checkout--redirect-page">

    {if (!$use_iframe)}
        <div class="page-title">
            <h1>{l s='Redirecting you to Cardlink payment gateway...' mod='cardlink_checkout'}</h1>
        </div>
    {/if}

    <form name="cardlink_checkout" action="{$action}" method="post" target="_self"
        enctype="application/x-www-form-urlencoded">
        {foreach from=$form_data key="fieldKey" item="fieldValue"}
            <input type="hidden" name="{$fieldKey}" value="{$fieldValue}" />
        {/foreach}
    </form>

    {literal}
        <script>
            window.addEventListener('load', function() {
                document.forms["cardlink_checkout"].submit();
            });
        </script>
    {/literal}
</body>

</html>