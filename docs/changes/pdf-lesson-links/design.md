# PDF insertion design

Proposed design, limited to request.md. Source: independent design-agent inspection.

Add one labelled Insert PDF toolbar control in the existing shared lesson editor. Open a compact Flux modal containing a labelled PDF file field, 10 MB help, link text (default selected text or filename), Upload and insert link and Cancel. No gallery or file-management screen.

Capture the editor selection before modal focus changes. Link selected text or insert the supplied label at the caret. Bind completion to the stable lesson record key, resolve the current model, restore the selection and focus, then end the link mark so subsequent typing is normal text. Save/reload uses the existing staged editor contract. Cancellation preserves content and returns focus.

Use ordinary anchors with target=_blank and rel=noopener noreferrer, plus accessible PDF/new-tab context. Respect editor authorization and existing operation guards, including fullscreen. Announce upload progress, disable duplicate submission and conflicting actions, and keep inline associated errors visible while preserving the lesson text. Failed upload must not insert a link.

Use existing semantic focus/style tokens and Flux modal behavior. The toolbar wraps; the modal remains single-column with wrapping filenames and reachable actions on narrow screens. English source strings and PT-BR translations apply. No deletion action: removing a link never deletes stored bytes.

Evidence locations: course-editor/root.blade.php unified toolbar and image modal; flux/editor/image.blade.php; content-editor.js editor map and insertion events; EditorCoordinator stable record-key insertion; course-editor.js media family/operation guards; app.css fullscreen and toolbar rules. This proposal is not runtime QA evidence.
