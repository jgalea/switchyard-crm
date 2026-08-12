# Switchyard CRM documentation

- [Setup](setup.md) — connecting a CRM, mapping fields, first sync
- [Access control](access-control.md) — gating content with tags
- [Developer guide](developers.md) — functions, filters, actions, WP-CLI
- [Adding a provider](adding-a-provider.md) — supporting another CRM

## How it fits together

```
WordPress event  →  Integration  →  UserSync / TagEngine  →  Provider  →  CRM
                                          ↑
CRM automation   →  Webhook listener  ────┘
```

An integration normalises a host plugin's event into fields and tags. `UserSync`
and `TagEngine` resolve the contact, diff against the cached state and decide
whether a call is needed at all. The provider translates into the CRM's own
shape. Everything crosses the network through one HTTP client.

Access control reads the tag cache in user meta, so a page load never waits on
the CRM.
