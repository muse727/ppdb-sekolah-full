// src/components/pendaftar/WhatsAppModal.tsx
'use client';

import React, { useEffect } from 'react';
import styles from './whatsapp-modal.module.css';

interface WhatsAppModalProps {
  isOpen: boolean;
  onClose: () => void;
  userName?: string;
}

export default function WhatsAppModal({ isOpen, onClose, userName }: WhatsAppModalProps) {
  useEffect(() => {
    if (!isOpen) return;
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose();
    };
    window.addEventListener('keydown', handleKeyDown);
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, [isOpen, onClose]);

  if (!isOpen) return null;

  const contacts = [
    {
      unit: 'SDIT',
      title: 'Admin SDIT Al-Mubarak',
      phone: '0895-1703-6100',
      link: `https://wa.me/6289517036100?text=${encodeURIComponent(
        `Halo Admin SDIT Al-Mubarak, saya wali dari ${userName || ''} ingin berkonsultasi mengenai pendaftaran SPMB.`
      )}`,
      badgeClass: styles.unitIconSd,
    },
    {
      unit: 'SMPIT',
      title: 'Admin SMPIT Al-Mubarak',
      phone: '0895-1232-2694',
      link: `https://wa.me/6289512322694?text=${encodeURIComponent(
        `Halo Admin SMPIT Al-Mubarak, saya wali dari ${userName || ''} ingin berkonsultasi mengenai pendaftaran SPMB.`
      )}`,
      badgeClass: styles.unitIconSmp,
    },
  ];

  return (
    <div className={styles.overlay} onClick={onClose} role="dialog" aria-modal="true">
      <div className={styles.modalSheet} onClick={(e) => e.stopPropagation()}>
        <div className={styles.dragHandleBar}>
          <div className={styles.dragHandle}></div>
        </div>

        <div className={styles.modalHeader}>
          <div className={styles.headerLeft}>
            <div className={styles.waBadgeIcon}>
              <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round">
                <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"></path>
              </svg>
            </div>
            <div className={styles.headerText}>
              <h3>Layanan Bantuan SPMB</h3>
              <p>Konsultasi & Layanan Cepat via WhatsApp</p>
            </div>
          </div>
          <button type="button" className={styles.closeBtn} onClick={onClose} aria-label="Tutup modal">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
              <line x1="18" y1="6" x2="6" y2="18"></line>
              <line x1="6" y1="6" x2="18" y2="18"></line>
            </svg>
          </button>
        </div>

        <div className={styles.modalBody}>
          <p className={styles.sectionNote}>
            Silakan pilih narahubung resmi sesuai jenjang yang ingin Anda tanyakan:
          </p>

          {contacts.map((c) => (
            <a
              key={c.unit}
              href={c.link}
              target="_blank"
              rel="noopener noreferrer"
              className={styles.contactCard}
              onClick={onClose}
            >
              <div className={styles.contactLeft}>
                <div className={`${styles.unitIcon} ${c.badgeClass}`}>
                  {c.unit}
                </div>
                <div className={styles.contactInfo}>
                  <h4>{c.title}</h4>
                  <div className={styles.contactNumber}>
                    <span>{c.phone}</span>
                    <span className={styles.statusPill}>Online</span>
                  </div>
                </div>
              </div>
              <div className={styles.chatArrowBtn}>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
                  <line x1="5" y1="12" x2="19" y2="12"></line>
                  <polyline points="12 5 19 12 12 19"></polyline>
                </svg>
              </div>
            </a>
          ))}

          <div className={styles.footerNote}>
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#16a34a" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
              <circle cx="12" cy="12" r="10"></circle>
              <polyline points="12 6 12 12 16 14"></polyline>
            </svg>
            <span>Jam operasional layanan pesan: Senin - Sabtu (08.00 - 15.00 WIB)</span>
          </div>
        </div>
      </div>
    </div>
  );
}
