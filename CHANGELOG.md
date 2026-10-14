# Release Notes for Embedded Posters

## 1.1.0 - 2026-10-14

- Posters are stored in an **Embedded Posters** volume, created on install and hidden from the Assets index and asset pickers.
- Removed the Volume and Folder settings. To store posters elsewhere, change the volume's filesystem.

## 1.0.0 - 2026-10-14

- Local copies of Embedded Assets poster images, fetched when an embed is saved and stored as ordinary image assets.
- `craft.embeddedPosters.poster(asset)`, with eager loading (`with(['embeddedPoster'])`) and automatic batching inside loops.
- Settings for the poster volume and folder. Changing them moves existing posters in a queue job.
- The `embedded-posters/fetch` console command for existing embeds.
