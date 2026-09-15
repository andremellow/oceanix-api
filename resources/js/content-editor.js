import { Node } from '@tiptap/core';
import Image from '@tiptap/extension-image';

const OceanixImage = Image.extend({
    addAttributes() {
        return {
            ...this.parent?.(),
            align: {
                default: 'center',
                parseHTML: (element) => element.getAttribute('data-align') || 'center',
                renderHTML: ({ align }) => ({ 'data-align': align }),
            },
            width: {
                default: '50',
                parseHTML: (element) => element.getAttribute('data-width') || '50',
                renderHTML: ({ width }) => ({ 'data-width': width }),
            },
        };
    },
});

const OceanixVideo = Node.create({
    name: 'oceanixVideo',
    group: 'block',
    atom: true,
    selectable: true,
    addAttributes() {
        return {
            previewUrl: { default: null, rendered: false },
            posterUrl: { default: null, rendered: false },
            title: { default: 'Video', rendered: false },
            aspectRatio: { default: '16/9', rendered: false },
        };
    },
    parseHTML: () => [{ tag: '[data-oceanix-video]' }],
    renderHTML: () => ['div', { 'data-oceanix-video': '', class: 'oceanix-video-block' }, ['span', {}, 'Video']],
    addNodeView() {
        return ({ node, editor }) => {
            const dom = document.createElement('div');
            dom.className = 'oceanix-video-block';
            dom.dataset.oceanixVideo = '';
            const editorElement = editor.view.dom.closest('[data-oceanix-editor-model]');
            const aspectRatio = editorElement?.dataset.oceanixVideoAspectRatio || node.attrs.aspectRatio || '16/9';
            const ratioParts = String(aspectRatio).split('/').map(Number);
            const ratio = ratioParts[0] > 0 && ratioParts[1] > 0 ? ratioParts[0] / ratioParts[1] : 16 / 9;
            dom.style.aspectRatio = String(ratio);
            dom.style.width = ratio < 1 ? `min(100%, ${Math.round(65 * ratio)}vh)` : 'min(100%, 960px)';
            const posterUrl = node.attrs.posterUrl || editorElement?.dataset.oceanixVideoPosterUrl;
            const title = node.attrs.title || editorElement?.dataset.oceanixVideoTitle || 'Video';

            if (!posterUrl) {
                const label = document.createElement('span');
                label.textContent = title;
                dom.append(label);

                return { dom };
            }

            const thumbnail = document.createElement('img');
            thumbnail.className = 'oceanix-video-thumbnail';
            thumbnail.src = posterUrl;
            thumbnail.alt = title;
            dom.append(thumbnail);

            const badge = document.createElement('span');
            badge.className = 'oceanix-video-play-badge';
            badge.setAttribute('aria-hidden', 'true');
            badge.textContent = '▶';
            dom.append(badge);

            return { dom };
        };
    },
    addCommands() {
        return {
            insertOceanixVideo: (attributes = {}) => ({ commands }) => commands.insertContent({
                type: this.name,
                attrs: attributes,
            }),
        };
    },
});

window.oceanixContentEditors = window.oceanixContentEditors || new Map();

let pendingPdf = null;
let insertedPdf = null;
window.oceanixCapturePdf = (button) => {
    pendingPdf = null;
    insertedPdf = null;
    const element = button.closest('[data-oceanix-editor-model]');
    const model = element?.dataset.oceanixEditorModel;
    const editor = window.oceanixContentEditors.get(model);
    if (!editor || editor.isDestroyed) return;
    const { from, to } = editor.state.selection;
    pendingPdf = { editor, model, recordKey: element.dataset.oceanixRecordKey, from, to,
        text: editor.state.doc.textBetween(from, to, ' '), token: crypto.randomUUID() };
};
window.oceanixOpenPdf = (button) => {
    window.oceanixCapturePdf(button);
    if (!pendingPdf) return;
    const { model, text, token } = pendingPdf;
    window.dispatchEvent(new CustomEvent('oceanix-open-pdf', { detail: { model, text, token } }));
};
const restoreInsertedPdf = () => {
    const inserted = insertedPdf;
    if (inserted?.closed && inserted?.rendered) {
        insertedPdf = null;
        // Restore only after the staged overlay and native dialog focus restoration.
        requestAnimationFrame(() => {
            const { editor, position } = inserted;
            if (editor.isDestroyed) return;
            editor.chain().setTextSelection(position).command(({ tr }) => { tr.setStoredMarks([]); return true; }).run();
            editor.view.focus();
            const point = editor.view.domAtPos(position, 1);
            const range = document.createRange();
            range.setStart(point.node, point.offset);
            const anchor = (point.node.nodeType === window.Node.ELEMENT_NODE ? point.node : point.node.parentElement)?.closest('a');
            if (anchor) {
                if (position <= editor.view.posAtDOM(anchor, 0)) range.setStartBefore(anchor);
                else range.setStartAfter(anchor);
            }
            range.collapse(true);
            const selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(range);
        });
    }
};
document.addEventListener('oceanix:editor-overlay-restored', (event) => {
    if (!insertedPdf) return;
    if (!event.target.contains(insertedPdf.editor.view.dom)) return;
    insertedPdf.rendered = true;
    restoreInsertedPdf();
});
window.oceanixCancelPdf = () => {
    if (insertedPdf) {
        insertedPdf.closed = true;
        restoreInsertedPdf();
        return;
    }
    const pending = pendingPdf;
    pendingPdf = null;
    if (pending && !pending.editor.isDestroyed) setTimeout(() => pending.editor.commands.focus(), 50);
};
document.addEventListener('oceanix:insert-pdf', (event) => {
    const pending = pendingPdf;
    if (!pending || pending.token !== event.detail.token || pending.recordKey !== event.detail.recordKey) return;
    const editor = window.oceanixContentEditors.get(event.detail.model);
    if (!editor || editor !== pending.editor || editor.isDestroyed) return;
    pendingPdf = null;
    const attrs = { href: event.detail.reference, target: '_blank', rel: 'noopener noreferrer' };
    const chain = editor.chain().focus().setTextSelection({ from: pending.from, to: pending.to });
    if (pending.from !== pending.to && event.detail.label === pending.text) {
        chain.setLink(attrs).setTextSelection(pending.to).command(({ tr }) => { tr.setStoredMarks([]); return true; }).run();
    } else {
        chain.insertContent({ type: 'text', text: event.detail.label, marks: [{ type: 'link', attrs }] }).command(({ tr }) => { tr.setStoredMarks([]); return true; }).run();
    }
    insertedPdf = { editor, position: pending.from !== pending.to && event.detail.label === pending.text
        ? pending.to : pending.from + event.detail.label.length };
    editor.view.dom.dispatchEvent(new CustomEvent('oceanix:pdf-inserted', { bubbles: true, detail: editor.getHTML() }));
});

const exitEditorFullscreen = (element) => {
    element.classList.remove('is-fullscreen');
    document.documentElement.classList.remove('overflow-hidden');
    document.body.classList.remove('overflow-hidden');
};

window.oceanixToggleEditorFullscreen = (element) => {
    if (!element) return;

    const entering = !element.classList.contains('is-fullscreen');
    document.querySelectorAll('.oceanix-content-editor.is-fullscreen')
        .forEach((editor) => exitEditorFullscreen(editor));

    if (!entering) return;

    element.classList.add('is-fullscreen');
    document.documentElement.classList.add('overflow-hidden');
    document.body.classList.add('overflow-hidden');
};

document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;

    const editor = document.querySelector('.oceanix-content-editor.is-fullscreen');
    if (editor) window.oceanixToggleEditorFullscreen(editor);
});

document.addEventListener('flux:editor', (event) => {
    event.detail.registerExtensions([OceanixImage, OceanixVideo]);
});

document.addEventListener('flux:editor:ready', (event) => {
    const element = event.target.closest('[data-oceanix-editor-model]');
    if (!element) return;

    const model = element.dataset.oceanixEditorModel;
    window.oceanixContentEditors.set(model, event.detail.editor);
});

document.addEventListener('oceanix:insert-image', (event) => {
    const editor = window.oceanixContentEditors.get(event.detail.model);
    if (!editor) return;

    editor.chain().focus().setImage({
        src: event.detail.url,
        alt: event.detail.alt || '',
        align: 'center',
        width: '50',
    }).run();
});

document.addEventListener('oceanix:insert-video', (event) => {
    window.oceanixContentEditors.get(event.detail.model)
        ?.chain().focus().insertOceanixVideo({
            previewUrl: event.detail.previewUrl || null,
            posterUrl: event.detail.posterUrl || null,
            title: event.detail.title || 'Video',
            aspectRatio: event.detail.aspectRatio || '16/9',
        }).run();
});
