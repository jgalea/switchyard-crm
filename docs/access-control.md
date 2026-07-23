# Access control

## Per post

Every post, page and public custom post type gets a Conduit access box in the
editor sidebar. Tick Restrict access, then choose tags and whether the visitor
needs any of them or all of them.

Restricting with no tags selected means "any logged-in user", which is a
deliberate and common configuration.

Anyone who can edit the post can always see it. Without that, a CRM outage would
lock a site owner out of their own content.

## Per taxonomy term

Set tags on a category or term and every post in it inherits the rule. A post
with its own rule overrides the inherited one.

## Where the rule is enforced

Gating only `the_content` leaks the material through other surfaces, so Conduit
applies the rule at four points:

1. `template_redirect` for the single view, redirecting or showing the message.
2. `pre_get_posts` to drop restricted posts from archives, search and feeds.
3. REST responses, which have content and excerpt emptied.
4. Menu items, widgets, blocks and page lists.

Query filtering can be switched off under Access if you are handling it yourself.

## What is not protected

**Uploaded files are not gated.** Restricting a post does not restrict a PDF,
video or image attached to it. Those files are served straight off disk by the
web server, and WordPress is never asked, so no plugin can gate them from PHP
alone. Anyone with the direct URL can fetch them regardless of their tags.

Gating files needs either web-server rules that route uploads through PHP, or
storing them outside the web root and streaming them through a handler. If you
are selling downloadable content, treat that as a requirement rather than a
detail.

Attachment pages, the WordPress-rendered page for an attachment, are excluded
from gating for the same reason: gating the page while the file stays open is
a false sense of security.

Two related limits worth knowing:

- The tag cache is refreshed on login, on webhook, and on a schedule. A tag
  removed directly in the CRM takes effect on the user's next login unless the
  CRM sends a webhook. Call `conduit_refresh_tags()` where you need it sooner.
- Anyone who can edit a post can always see it, even without the tags. That is
  deliberate: without it a CRM outage would lock an author out of their own
  drafts. If your editors should also be gated, use the `conduit_can_access`
  filter.

## Shortcodes

```
[conduit_if tag="VIP"]Members only.[/conduit_if]
[conduit_if tag="Trial" not="true"]Hidden from trial users.[/conduit_if]
[conduit_if tag="VIP,Founder" match="all"]Both tags required.[/conduit_if]
[conduit_logged_in]…[/conduit_logged_in]
[conduit_logged_out]…[/conduit_logged_out]
[conduit_field key="first_name" default="there"]
```

## How tags are read

Access decisions read a cached tag set in user meta, so no page load waits on
the CRM. The cache is refreshed on login, when a webhook arrives, and whenever
this site writes a tag.

That means a tag removed directly in the CRM takes effect on the user's next
login, or immediately if the CRM sends a webhook. Sites that need it sooner can
call `conduit_refresh_tags()` wherever it matters.
