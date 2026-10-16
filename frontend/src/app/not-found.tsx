"use client";

import React, { useState, useEffect, useRef } from "react";
import Link from "next/link";

interface NumberItem {
  id: string;
  char: string;
  x: number;
  y: number;
  rotation: number;
}

export default function NotFound() {
  // Posisi draggable untuk angka 4, 0, 4
  const [numbers, setNumbers] = useState<NumberItem[]>([
    { id: "four-1", char: "4", x: 0, y: 0, rotation: -4 },
    { id: "zero", char: "0", x: 0, y: 0, rotation: 2 },
    { id: "four-2", char: "4", x: 0, y: 0, rotation: 5 },
  ]);

  const [activeDragId, setActiveDragId] = useState<string | null>(null);
  const dragStartRef = useRef<{ mouseX: number; mouseY: number; initialX: number; initialY: number } | null>(null);

  // Tracking posisi cursor untuk mata interaktif (pupil tracking ala James Walsh Studio)
  const [pupilOffset, setPupilOffset] = useState({ x: 0, y: 0 });
  const eyesContainerRef = useRef<HTMLDivElement | null>(null);

  useEffect(() => {
    const handleMouseMove = (e: MouseEvent) => {
      // 1. Hitung pupil offset
      if (eyesContainerRef.current) {
        const rect = eyesContainerRef.current.getBoundingClientRect();
        const eyesCenterX = rect.left + rect.width / 2;
        const eyesCenterY = rect.top + rect.height / 2;

        const deltaX = e.clientX - eyesCenterX;
        const deltaY = e.clientY - eyesCenterY;
        const angle = Math.atan2(deltaY, deltaX);
        const distance = Math.min(Math.hypot(deltaX, deltaY), 350);
        const radius = (distance / 350) * 7; // max pupil travel: 7px

        setPupilOffset({
          x: Math.cos(angle) * radius,
          y: Math.sin(angle) * radius,
        });
      }

      // 2. Dragging update
      if (activeDragId && dragStartRef.current) {
        const dx = e.clientX - dragStartRef.current.mouseX;
        const dy = e.clientY - dragStartRef.current.mouseY;
        const newX = dragStartRef.current.initialX + dx;
        const newY = dragStartRef.current.initialY + dy;

        setNumbers((prev) =>
          prev.map((item) =>
            item.id === activeDragId
              ? { ...item, x: newX, y: newY, rotation: Math.max(-25, Math.min(25, newX * 0.05)) }
              : item
          )
        );
      }
    };

    const handleMouseUp = () => {
      setActiveDragId(null);
      dragStartRef.current = null;
    };

    window.addEventListener("mousemove", handleMouseMove);
    window.addEventListener("mouseup", handleMouseUp);
    return () => {
      window.removeEventListener("mousemove", handleMouseMove);
      window.removeEventListener("mouseup", handleMouseUp);
    };
  }, [activeDragId]);

  const handlePointerDown = (id: string, e: React.PointerEvent) => {
    const item = numbers.find((n) => n.id === id);
    if (!item) return;
    setActiveDragId(id);
    dragStartRef.current = {
      mouseX: e.clientX,
      mouseY: e.clientY,
      initialX: item.x,
      initialY: item.y,
    };
    (e.target as HTMLElement).setPointerCapture?.(e.pointerId);
  };

  const handleReset = () => {
    setNumbers([
      { id: "four-1", char: "4", x: 0, y: 0, rotation: -4 },
      { id: "zero", char: "0", x: 0, y: 0, rotation: 2 },
      { id: "four-2", char: "4", x: 0, y: 0, rotation: 5 },
    ]);
  };

  return (
    <div
      style={{
        backgroundColor: "#ffffff",
        color: "#0f3d2e",
        minHeight: "100vh",
        display: "flex",
        flexDirection: "column",
        justifyContent: "space-between",
        alignItems: "center",
        padding: "2rem 1.5rem",
        fontFamily: "var(--font-geist-sans), system-ui, -apple-system, sans-serif",
        userSelect: "none",
        overflow: "hidden",
        position: "relative",
      }}
    >
      {/* Top Header ala Studio Portfolio */}
      <header
        style={{
          width: "100%",
          maxWidth: "1100px",
          display: "flex",
          justifyContent: "space-between",
          alignItems: "center",
          fontSize: "0.875rem",
          letterSpacing: "0.08em",
          textTransform: "uppercase",
          fontWeight: 600,
          color: "#00aa5b",
          borderBottom: "1px solid rgba(0, 170, 91, 0.15)",
          paddingBottom: "1rem",
        }}
      >
        <Link
          href="/"
          style={{
            display: "inline-flex",
            alignItems: "center",
            gap: "0.5rem",
            color: "#0f3d2e",
            textDecoration: "none",
            fontWeight: 700,
          }}
        >
          <span
            style={{
              width: "8px",
              height: "8px",
              borderRadius: "50%",
              backgroundColor: "#00aa5b",
              display: "inline-block",
            }}
          />
          SPMB SIT AL-MUBARAK
        </Link>
        <span style={{ color: "#00aa5b", opacity: 0.8 }}>PAGE NOT FOUND</span>
      </header>

      {/* Main Interactive Stage */}
      <main
        style={{
          flex: 1,
          width: "100%",
          maxWidth: "1000px",
          display: "flex",
          flexDirection: "column",
          alignItems: "center",
          justifyContent: "center",
          position: "relative",
          margin: "2rem 0",
        }}
      >
        {/* Playful Interactive Eyes (James Walsh Studio signature) */}
        <div
          ref={eyesContainerRef}
          title="Aku mengawasimu 👀"
          style={{
            display: "flex",
            alignItems: "center",
            gap: "10px",
            padding: "8px 16px",
            backgroundColor: "#f0fdf4",
            border: "1.5px solid #00aa5b",
            borderRadius: "9999px",
            marginBottom: "1.5rem",
            boxShadow: "0 4px 14px rgba(0, 170, 91, 0.08)",
            cursor: "pointer",
            transition: "transform 0.2s ease",
          }}
        >
          {/* Mata Kiri */}
          <div
            style={{
              width: "28px",
              height: "28px",
              backgroundColor: "#ffffff",
              borderRadius: "50%",
              border: "2px solid #00aa5b",
              display: "flex",
              alignItems: "center",
              justifyContent: "center",
              position: "relative",
              overflow: "hidden",
            }}
          >
            <div
              style={{
                width: "11px",
                height: "11px",
                backgroundColor: "#00aa5b",
                borderRadius: "50%",
                transform: `translate(${pupilOffset.x}px, ${pupilOffset.y}px)`,
                transition: "transform 0.05s linear",
              }}
            />
          </div>

          {/* Mata Kanan */}
          <div
            style={{
              width: "28px",
              height: "28px",
              backgroundColor: "#ffffff",
              borderRadius: "50%",
              border: "2px solid #00aa5b",
              display: "flex",
              alignItems: "center",
              justifyContent: "center",
              position: "relative",
              overflow: "hidden",
            }}
          >
            <div
              style={{
                width: "11px",
                height: "11px",
                backgroundColor: "#00aa5b",
                borderRadius: "50%",
                transform: `translate(${pupilOffset.x}px, ${pupilOffset.y}px)`,
                transition: "transform 0.05s linear",
              }}
            />
          </div>

          <span
            style={{
              fontSize: "0.75rem",
              fontWeight: 600,
              color: "#0f3d2e",
              letterSpacing: "0.04em",
              marginLeft: "4px",
            }}
          >
            Tarik angkanya sesukamu!
          </span>
        </div>

        {/* Draggable Numbers 4 - 0 - 4 */}
        <div
          style={{
            display: "flex",
            alignItems: "center",
            justifyContent: "center",
            gap: "clamp(0.5rem, 3vw, 2.5rem)",
            touchAction: "none",
            width: "100%",
          }}
        >
          {numbers.map((item) => {
            const isDragging = activeDragId === item.id;
            return (
              <div
                key={item.id}
                onPointerDown={(e) => handlePointerDown(item.id, e)}
                style={{
                  transform: `translate3d(${item.x}px, ${item.y}px, 0) rotate(${item.rotation}deg)`,
                  transition: isDragging ? "none" : "transform 0.25s cubic-bezier(0.34, 1.56, 0.64, 1)",
                  cursor: isDragging ? "grabbing" : "grab",
                  userSelect: "none",
                  touchAction: "none",
                }}
              >
                <span
                  style={{
                    display: "block",
                    fontSize: "clamp(7rem, 24vw, 17rem)",
                    lineHeight: 0.85,
                    fontWeight: 900,
                    letterSpacing: "-0.05em",
                    color: "#00aa5b",
                    textShadow: isDragging
                      ? "0 20px 40px rgba(0, 170, 91, 0.25)"
                      : "0 10px 25px rgba(0, 170, 91, 0.12)",
                    transition: "color 0.2s, text-shadow 0.2s",
                  }}
                >
                  {item.char}
                </span>
              </div>
            );
          })}
        </div>

        {/* Caption & Context Text */}
        <div
          style={{
            marginTop: "2.5rem",
            textAlign: "center",
            maxWidth: "480px",
            zIndex: 10,
          }}
        >
          <h1
            style={{
              fontSize: "clamp(1.25rem, 3vw, 1.75rem)",
              fontWeight: 800,
              color: "#0f3d2e",
              marginBottom: "0.5rem",
              letterSpacing: "-0.02em",
            }}
          >
            Wah, nyasar ke mana nih?
          </h1>
          <p
            style={{
              fontSize: "0.95rem",
              color: "#475569",
              lineHeight: 1.6,
              marginBottom: "1.75rem",
            }}
          >
            Halaman yang kamu cari tidak ditemukan atau mungkin sudah dipindahkan.
          </p>

          {/* Action Buttons */}
          <div
            style={{
              display: "flex",
              alignItems: "center",
              justifyContent: "center",
              gap: "0.75rem",
              flexWrap: "wrap",
            }}
          >
            <Link
              href="/"
              style={{
                display: "inline-flex",
                alignItems: "center",
                gap: "0.5rem",
                backgroundColor: "#00aa5b",
                color: "#ffffff",
                padding: "0.75rem 1.75rem",
                borderRadius: "9999px",
                fontWeight: 600,
                fontSize: "0.925rem",
                textDecoration: "none",
                boxShadow: "0 4px 14px rgba(0, 170, 91, 0.25)",
                transition: "all 0.2s ease",
              }}
              onMouseEnter={(e) => {
                e.currentTarget.style.backgroundColor = "#008c4a";
                e.currentTarget.style.transform = "translateY(-2px)";
              }}
              onMouseLeave={(e) => {
                e.currentTarget.style.backgroundColor = "#00aa5b";
                e.currentTarget.style.transform = "translateY(0)";
              }}
            >
              ← Kembali ke Beranda
            </Link>

            <button
              onClick={handleReset}
              type="button"
              style={{
                display: "inline-flex",
                alignItems: "center",
                gap: "0.4rem",
                backgroundColor: "#ffffff",
                color: "#00aa5b",
                border: "1.5px solid #00aa5b",
                padding: "0.75rem 1.25rem",
                borderRadius: "9999px",
                fontWeight: 600,
                fontSize: "0.875rem",
                cursor: "pointer",
                transition: "all 0.2s ease",
              }}
              onMouseEnter={(e) => {
                e.currentTarget.style.backgroundColor = "#f0fdf4";
              }}
              onMouseLeave={(e) => {
                e.currentTarget.style.backgroundColor = "#ffffff";
              }}
            >
              ↺ Reset Angka
            </button>
          </div>
        </div>
      </main>

      {/* Footer Minimalis */}
      <footer
        style={{
          width: "100%",
          maxWidth: "1100px",
          display: "flex",
          justifyContent: "space-between",
          alignItems: "center",
          fontSize: "0.8rem",
          color: "#94a3b8",
          borderTop: "1px solid rgba(0, 170, 91, 0.15)",
          paddingTop: "1rem",
          flexWrap: "wrap",
          gap: "0.5rem",
        }}
      >
        <span>© 2026 Yayasan SIT Al-Mubarak. All rights reserved.</span>
        <span style={{ color: "#00aa5b", fontWeight: 500 }}>SDIT & SMPIT Al-Mubarak</span>
      </footer>
    </div>
  );
}
