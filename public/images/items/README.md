# Item pictures

One subdirectory per **type** of item, named by `App\Models\Item::imageType()` —
the hardware kind (`cpu`, `psu`, `thermal`, …), the program kind (`icebreaker`,
`daemon`, …), `imprint`, or the inventory an ordinary object lives in
(`physical`, `data`). It follows the market's categories: `weapons/<class>`
(`weapons/pistol`), `ammunition/<calibre>` (the calibre slugged: `9mm`,
`5-56x45`; made as they are met), `drugs` and `implants`; and a module sits
under its variant (`ram/opto`, `cpu/socket_k`). A default in a parent folder
stands in for the sections below it.

Inside one of them, by the item's **id**:

| File | Is | Size |
| --- | --- | --- |
| `<id>.jpg` | what the card shows | **16:9**, source about **640×360** |
| `<id>_full.jpg` | what the modal opens | any dimensions |
| `_default.jpg`, `_default_full.jpg` | what stands in for that type | as above |

`_default.jpg` here, in `items/` itself, stands in for every type that has not
drawn its own — `App\Support\Image` walks the default back up the path, and only
the default. An item's own picture is looked for in its type's folder and
nowhere else.

The `_default.jpg` shipped here is a **placeholder** — an empty notched slot in
the kit's palette, no text and no colour, so it does not shout on a screen full
of real pictures. Replace it, or override it per type, as art arrives.

Nothing is required: an item with no picture, no type default and no root
default renders no image at all rather than a broken one.
