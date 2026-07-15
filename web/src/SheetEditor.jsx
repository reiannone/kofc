import React from 'react';
import {
  Bold, Italic, Strikethrough, List, ListOrdered, Heading1, Heading2, Heading3, Quote, Code, Undo2, Redo2, Link as LinkIcon,
} from 'lucide-react';
import { useEditor, EditorContent } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';
import LinkExt from '@tiptap/extension-link';
import { Markdown } from 'tiptap-markdown';

// Self-contained palette so the editor looks identical wherever it is mounted — the agent
// app (App.jsx) and the supervisor admin (SupervisorAdmin.jsx) live on different routes and
// style scopes, so this component owns its own colors and CSS rather than depending on either.
const E = { navy: '#1b2a4a', blue: '#2f5597', border: '#dfe3ea', text: '#222', sub: '#666' };

const SHEET_EDITOR_CSS = `
.tt .ProseMirror{min-height:360px;padding:14px 16px;outline:none;font-size:13px;line-height:1.6;color:${E.text}}
.tt .ProseMirror:focus{outline:none}
.tt .ProseMirror>:first-child{margin-top:0}
.tt .ProseMirror>:last-child{margin-bottom:0}
.tt .ProseMirror p{margin:0 0 8px}
.tt .ProseMirror h1{font-size:18px;font-weight:700;color:${E.navy};margin:12px 0 6px;line-height:1.3}
.tt .ProseMirror h2{font-size:15px;font-weight:700;color:${E.navy};margin:12px 0 6px;line-height:1.3}
.tt .ProseMirror h3{font-size:13px;font-weight:700;color:${E.navy};margin:10px 0 4px;line-height:1.3}
.tt .ProseMirror ul,.tt .ProseMirror ol{margin:0 0 8px;padding-left:22px}
.tt .ProseMirror li{margin:2px 0}
.tt .ProseMirror li>p{margin:0}
.tt .ProseMirror blockquote{margin:0 0 8px;padding:2px 0 2px 10px;border-left:3px solid ${E.border};color:${E.sub}}
.tt .ProseMirror code{background:#f0f2f6;border:1px solid ${E.border};border-radius:4px;padding:1px 4px;font-size:12px;font-family:ui-monospace,Menlo,Consolas,monospace}
.tt .ProseMirror pre{background:#f0f2f6;border:1px solid ${E.border};border-radius:6px;padding:10px;overflow:auto;margin:0 0 8px}
.tt .ProseMirror pre code{background:none;border:none;padding:0}
.tt .ProseMirror a{color:${E.blue};text-decoration:underline}
.tt .ProseMirror hr{border:none;border-top:1px solid ${E.border};margin:10px 0}
`;

// Inject the editor CSS exactly once, regardless of how many editors mount / unmount.
let _cssInjected = false;
function useSheetEditorCss() {
  React.useEffect(() => {
    if (_cssInjected || typeof document === 'undefined') return;
    _cssInjected = true;
    const el = document.createElement('style');
    el.setAttribute('data-sheet-editor', '');
    el.textContent = SHEET_EDITOR_CSS;
    document.head.appendChild(el);
  }, []);
}

// A single WYSIWYG toolbar button. onMouseDown-preventDefault keeps the editor selection
// from collapsing when the button is clicked.
function ToolbarBtn({ active, disabled, onClick, title, children }) {
  return (
    <button type="button" title={title} disabled={disabled}
      onMouseDown={(e) => e.preventDefault()} onClick={onClick}
      style={{ display: 'inline-flex', alignItems: 'center', justifyContent: 'center', width: 30, height: 30,
        borderRadius: 6, cursor: disabled ? 'default' : 'pointer',
        border: `1px solid ${active ? E.blue : E.border}`, background: active ? '#eef3fb' : '#fff',
        color: disabled ? '#c2c8d2' : (active ? E.blue : E.sub) }}>
      {children}
    </button>
  );
}

/**
 * WYSIWYG editor for the Scenario Worksheet. The document is edited as rich text but the
 * STORED format stays Markdown — `value` in and `onChange` out are both Markdown strings —
 * so the supervisor redline diff and version history keep working on the same text they
 * always have. tiptap-markdown handles parse (setContent) and serialize (getMarkdown).
 *
 * Shared verbatim between the agent app and the supervisor admin so the two Scenario
 * Worksheet surfaces stay identical.
 */
export function SheetEditor({ value, onChange, editable = true }) {
  useSheetEditorCss();
  const editor = useEditor({
    extensions: [
      StarterKit,
      LinkExt.configure({ openOnClick: false, autolink: true }),
      Markdown.configure({ html: false, tightLists: true, bulletListMarker: '-', linkify: true, transformPastedText: true }),
    ],
    content: value || '',
    editable,
    onUpdate: ({ editor }) => onChange(editor.storage.markdown.getMarkdown()),
  });

  // External value changes (generate/regenerate a sheet, open another deal) -> load into the
  // editor, but only when it truly differs from what's already there, so onUpdate can't loop.
  React.useEffect(() => {
    if (!editor) return;
    const current = editor.storage.markdown.getMarkdown();
    if ((value || '') !== current) {
      editor.commands.setContent(value || '', false); // false = don't emit an update event
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [value, editor]);

  React.useEffect(() => {
    if (editor) editor.setEditable(editable);
  }, [editor, editable]);

  if (!editor) return null;
  const on = (name, attrs) => editor.isActive(name, attrs);
  const sep = <div style={{ width: 1, alignSelf: 'stretch', background: E.border, margin: '2px 4px' }} />;

  return (
    <div style={{ border: `1px solid ${E.border}`, borderRadius: 8, background: '#fff', overflow: 'hidden' }}>
      <div style={{ display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: 4, padding: 8, borderBottom: `1px solid ${E.border}`, background: '#fafbfc' }}>
        <ToolbarBtn active={on('heading', { level: 1 })} onClick={() => editor.chain().focus().toggleHeading({ level: 1 }).run()} title="Heading 1"><Heading1 size={16} /></ToolbarBtn>
        <ToolbarBtn active={on('heading', { level: 2 })} onClick={() => editor.chain().focus().toggleHeading({ level: 2 }).run()} title="Heading 2"><Heading2 size={16} /></ToolbarBtn>
        <ToolbarBtn active={on('heading', { level: 3 })} onClick={() => editor.chain().focus().toggleHeading({ level: 3 }).run()} title="Heading 3"><Heading3 size={16} /></ToolbarBtn>
        {sep}
        <ToolbarBtn active={on('bold')} onClick={() => editor.chain().focus().toggleBold().run()} title="Bold"><Bold size={16} /></ToolbarBtn>
        <ToolbarBtn active={on('italic')} onClick={() => editor.chain().focus().toggleItalic().run()} title="Italic"><Italic size={16} /></ToolbarBtn>
        <ToolbarBtn active={on('strike')} onClick={() => editor.chain().focus().toggleStrike().run()} title="Strikethrough"><Strikethrough size={16} /></ToolbarBtn>
        <ToolbarBtn active={on('code')} onClick={() => editor.chain().focus().toggleCode().run()} title="Inline code"><Code size={16} /></ToolbarBtn>
        {sep}
        <ToolbarBtn active={on('bulletList')} onClick={() => editor.chain().focus().toggleBulletList().run()} title="Bullet list"><List size={16} /></ToolbarBtn>
        <ToolbarBtn active={on('orderedList')} onClick={() => editor.chain().focus().toggleOrderedList().run()} title="Numbered list"><ListOrdered size={16} /></ToolbarBtn>
        <ToolbarBtn active={on('blockquote')} onClick={() => editor.chain().focus().toggleBlockquote().run()} title="Quote"><Quote size={16} /></ToolbarBtn>
        {sep}
        <ToolbarBtn active={on('link')} title="Link" onClick={() => {
          const prev = editor.getAttributes('link').href || '';
          const url = window.prompt('Link URL', prev);
          if (url === null) return;
          if (url.trim() === '') { editor.chain().focus().extendMarkRange('link').unsetLink().run(); return; }
          editor.chain().focus().extendMarkRange('link').setLink({ href: url.trim() }).run();
        }}><LinkIcon size={16} /></ToolbarBtn>
        {sep}
        <ToolbarBtn disabled={!editor.can().undo()} onClick={() => editor.chain().focus().undo().run()} title="Undo"><Undo2 size={16} /></ToolbarBtn>
        <ToolbarBtn disabled={!editor.can().redo()} onClick={() => editor.chain().focus().redo().run()} title="Redo"><Redo2 size={16} /></ToolbarBtn>
      </div>
      <EditorContent editor={editor} className="tt" />
    </div>
  );
}

export default SheetEditor;
