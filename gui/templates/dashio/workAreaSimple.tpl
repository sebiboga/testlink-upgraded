{*
TestLink Open Source Project - http://testlink.sourceforge.net/
Purpose: show workarea with simple structure title + content + link
Dashio theme: renders inside mainframe iframe, so no need for full HTML structure
*}
<!DOCTYPE html>
<html>
<body>

{if isset($title) && $title ne ''}
	<h1 class="title">{$title|escape}</h1>
{/if}

<div class="workBack">

{if $content ne ''}
	{$content}
{/if}

{* 20060809 - franciscom - if user can solve the problem give him/her the url *}
{if isset($link_to_op) && $link_to_op ne ''}
  <p><a href="{$basehref}{$link_to_op}">{$hint_text}</a>
{/if}
	
</div>

</body>
</html>