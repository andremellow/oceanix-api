import { Editor } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import Image from '@tiptap/extension-image';
import { Markdown } from '@tiptap/markdown';

const imageMetadata = (align = 'center', width = '50%') => `oceanix:align=${align};width=${width}`;

const toEditorMarkdown = (markdown) => markdown.replace(
    /^:::image\{src="([^"]+)"(?: align="(left|right|center)")?(?: width="(25%|40%|50%|75%|100%)")?(?: alt="([^"]*)")?\}\s*$/gm,
    (_, src, align = 'center', width = '50%', alt = '') => `![${alt}](${src} "${imageMetadata(align, width)}")`,
);

const toStoredMarkdown = (markdown) => markdown.replace(
    /^!\[([^\]]*)\]\((\S+)\s+"oceanix:align=(left|right|center);width=(25%|40%|50%|75%|100%)"\)\s*$/gm,
    (_, alt, src, align, width) => `:::image{src="${src}" align="${align}" width="${width}" alt="${alt}"}`,
);

window.oceanixMarkdownEditors = window.oceanixMarkdownEditors || new Map();
window.oceanixInsertContentImage = ({ model, url, alt = '' }) => {
    const editor = window.oceanixMarkdownEditors.get(model);
    if (!editor) return false;

    editor.chain().focus().setImage({ src: url, alt, title: imageMetadata() }).run();
    return true;
};

document.addEventListener('alpine:init', () => {
    window.Alpine.data('markdownEditor', ({ model, content = '', labels = {}, imageLibrary = false }) => ({
        editor: null,
        saveTimer: null,
        selectedImagePos: null,

        init() {
            this.editor = new Editor({
                element: this.$refs.surface,
                extensions: [StarterKit.configure({ link: { openOnClick: false } }), Image.configure({ resize: { enabled: true } }), Markdown],
                content: toEditorMarkdown(content || ''),
                contentType: 'markdown',
                editorProps: { attributes: { class: 'visual-markdown-surface lesson-content' } },
                onUpdate: ({ editor }) => {
                    window.clearTimeout(this.saveTimer);
                    this.saveTimer = window.setTimeout(() => this.$wire.set(model, toStoredMarkdown(editor.getMarkdown())), 450);
                },
                onSelectionUpdate: ({ editor }) => {
                    if (editor.state.selection.node?.type.name === 'image') {
                        this.selectedImagePos = editor.state.selection.from;
                    }
                },
            });
            window.oceanixMarkdownEditors.set(model, this.editor);
        },

        destroy() {
            if (window.oceanixMarkdownEditors.get(model) === this.editor) {
                window.oceanixMarkdownEditors.delete(model);
            }
            this.editor?.destroy();
        },
        run(command) { command(this.editor.chain().focus()).run(); },
        paragraph() { this.run(chain => chain.setParagraph()); },
        heading(level) { this.run(chain => chain.toggleHeading({ level })); },
        bold() { this.run(chain => chain.toggleBold()); },
        italic() { this.run(chain => chain.toggleItalic()); },
        bulletList() { this.run(chain => chain.toggleBulletList()); },
        orderedList() { this.run(chain => chain.toggleOrderedList()); },
        blockquote() { this.run(chain => chain.toggleBlockquote()); },
        undo() { this.editor.chain().focus().undo().run(); },
        redo() { this.editor.chain().focus().redo().run(); },

        addLink() {
            const href = window.prompt(labels.linkUrl || 'Link URL');
            if (href) this.editor.chain().focus().extendMarkRange('link').setLink({ href }).run();
        },

        addImage() {
            if (imageLibrary) {
                this.$wire.openImageLibrary(model);
                return;
            }

            const src = window.prompt(labels.imageUrl || 'Image URL');
            if (!src) return;
            const alt = window.prompt(labels.imageDescription || 'Image description') || '';
            this.editor.chain().focus().setImage({ src, alt, title: imageMetadata() }).run();
        },

        alignImage(align) {
            const selectedPosition = this.editor.state.selection.node?.type.name === 'image'
                ? this.editor.state.selection.from
                : this.selectedImagePos;
            if (selectedPosition === null) return;

            const image = this.editor.state.doc.nodeAt(selectedPosition);
            if (image?.type.name !== 'image') return;

            const current = image.attrs.title || '';
            const width = current.match(/width=(25%|40%|50%|75%|100%)/)?.[1] || '50%';
            const transaction = this.editor.state.tr.setNodeMarkup(selectedPosition, undefined, {
                ...image.attrs,
                title: imageMetadata(align, width),
            });
            this.editor.view.dispatch(transaction);
            this.editor.commands.focus();
        },

        addVideo() {
            this.editor.chain().focus().insertContent('\n:::video\n', { contentType: 'markdown' }).run();
        },
    }));
});
