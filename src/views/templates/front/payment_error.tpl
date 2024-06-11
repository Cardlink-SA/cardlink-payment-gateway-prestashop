{extends file='page.tpl'}
{block name="page_content"}
	{capture name=path}{l s='Cardlink - Pay with card' mod='cardlink_checkout'}{/capture}

	<h1 class="page-heading">{l s='An error occured during payment' mod='cardlink_checkout'}</h1>

	<p class="warning">
		<strong>{l s='An error occured during payment' mod='cardlink_checkout'}.</strong>
		{if $error}
			<br /><br />
			{l s='Error message' mod='cardlink_checkout'}: {$error|escape:'htmlall':'UTF-8'}
		{/if}
	</p>
	<br />
	<div class="text-sm-center">
		<a id="btn-retry-cart" href="{$urls.pages.index}" class="btn btn-primary" rel="nofollow">
			{l s='Continue shopping' d='Shop.Theme.Actions'}
		</a>
		<a id="btn-retry-checkout" href="{$urls.pages.order}" class="btn btn-primary" rel="nofollow">
			{l s='Proceed to checkout' d='Shop.Theme.Actions'}
		</a>
	</div>
{/block}