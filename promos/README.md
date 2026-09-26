# Plugin Store promo images

Seven 1920×1080 JPEGs for the Erpy for NetSuite listing on the Craft Plugin Store, in the theme of
Erpy's page at [justinholt.com/plugins/craft-erpy](https://justinholt.com/plugins/craft-erpy).

```bash
./build.sh          # all slides
./build.sh "2 4"    # just slides 2 and 4
```

Output lands in `out/` as `erpy-netsuite-promo-N.jpg`. `fonts.css` is generated and not checked in.

**`slides.html` is generated — don't edit it here.** Every add-on deck comes from one template in
the Erpy repo, `craft-erpy/promos/addons/generate.py`, so the twelve stay consistent. Slide 2 (what
it syncs) and slide 3 (the connection form) are drawn from the connector's own `capabilities()` and
`settingsFields()`; the rest of the copy is in `craft-erpy/promos/addons/decks.json`. The icon is
generated too, by `craft-erpy/promos/icon-gen.py`.

| # | Slide |
|---|-------|
| 1 | Cover — the add-on's icon, free, requires Erpy |
| 2 | What it syncs, per entity and direction |
| 3 | The connection form |
| 4 | Three NetSuite traps the connector already handles |
| 5 | A mapping rule correcting the connector |
| 6 | What Erpy does for every connector |
| 7 | The twelve add-ons, and how to install this one |
