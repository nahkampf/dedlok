@props(['entry'])

{{-- The enlarged picture behind an item card's image.

     Rendered beside whatever lists the item, for the same reason
     <x-item-destroy> is: the card is cloned into a shared modal, and a dialog
     cloned along with it would be a second copy of itself in the top layer.

     Keyed by the ROW and not by the item, so two of the same thing in two
     rows are two dialogs rather than one duplicated id. The card names the
     same id — see <x-item-detail>. --}}
<x-entity-image-dialog for="image-{{ $entry->id }}"
                       :kind="$entry->item->imageKind()"
                       :id="$entry->item->id"
                       :alt="$entry->item->displayName()" />
