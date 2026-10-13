'use client';

import { useEffect, useId, useRef, type ReactNode } from 'react';
import styles from './admin-reports.module.css';

export default function AdminReportModal({ title, children, onClose, returnFocusTo }: { title: string; children: ReactNode; onClose: () => void; returnFocusTo?: HTMLElement | null }) {
    const dialog = useRef<HTMLDialogElement>(null);
    const titleId = useId();
    useEffect(() => {
        const element = dialog.current;
        const previous = returnFocusTo ?? document.activeElement;
        const overflow = document.body.style.overflow;
        element?.showModal();
        document.body.style.overflow = 'hidden';
        return () => {
            element?.close();
            document.body.style.overflow = overflow;
            if (previous instanceof HTMLElement) previous.focus();
        };
    }, [returnFocusTo]);
    return (
        <dialog ref={dialog} className={styles.modal} aria-labelledby={titleId} onCancel={e => { e.preventDefault(); onClose(); }} onKeyDown={e => {
            if (e.key !== 'Tab') return;
            const controls = Array.from(e.currentTarget.querySelectorAll<HTMLElement>('button:not(:disabled), a[href], input:not(:disabled), select:not(:disabled), textarea:not(:disabled), [tabindex="0"]'));
            const first = controls[0];
            const last = controls[controls.length - 1];
            if (first && ((e.shiftKey && document.activeElement === first) || (!e.shiftKey && document.activeElement === last))) {
                e.preventDefault();
                (e.shiftKey ? last : first).focus();
            }
        }}>
            <header className={styles.modalHeader}>
                <h2 id={titleId}>{title}</h2>
                <button type="button" className={styles.outline} onClick={onClose} autoFocus>Tutup</button>
            </header>
            {children}
        </dialog>
    );
}
