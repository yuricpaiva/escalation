# Escalation administration

<!-- impeccable:design-schema 1 -->

## Authority

The GLPI 11 administration interface is the visual authority. Escalation extends its Tabler icons, Bootstrap utilities, tables, forms, badges, alerts and tab navigation instead of introducing a separate brand layer.

## Surface mode

Operate: administrators repeatedly scan dense configuration, correct validation errors and inspect execution evidence.

## Interaction contract

- The Visual Editor is the recommended surface; existing forms remain available as Advanced mode.
- The editor uses a component palette, a constrained flow canvas and a contextual properties panel.
- Draft is always explicit; publication is blocked by contextual errors and produces an immutable version.
- Conditions are visually attached to Start because they filter activation rather than branch during execution.
- Canvas connections only allow forward paths supported by the engine. Advanced mode keeps accessible move controls and labelled destinations.
- GLPI records are selected by searchable names with entity-aware asynchronous queries; internal IDs and JSON are never exposed.
- Monitoring uses summary status first, then a chronological audit table and action errors.
- Empty, invalid, pending-configuration and automation-error states always explain the next permitted action.

## Responsive behavior

Forms stack labels above controls on narrow screens. In the editor, the palette becomes a drawer and properties become an off-canvas panel while the flow remains usable. Wide configuration tables become bordered scroll regions without hiding actions or status. Touch targets remain at least the GLPI native control height.

## Visual editor surface

- The GLPI 11 administration interface remains the authority: native typography, Tabler icons, theme variables and control sizes.
- Approval, action, condition, start and finish nodes use restrained semantic color while labels carry the meaning.
- Errors appear both on the affected block and in a keyboard-accessible summary that opens its properties.
- Motion is limited to panel transitions and node feedback, and is removed when reduced motion is requested.
