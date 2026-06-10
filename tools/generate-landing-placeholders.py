#!/usr/bin/env python3
"""Generate landing-page placeholder PNGs.

The React landing-page components reference defaults at
`/screenshots/saas/{hero-default,hero,employee-management,...}.png`
(and a `non-saas` parallel). The files were never committed to the repo
and lived only on the previous developer's machine, so on every fresh
clone — and on production — the hero panel and screenshots gallery 404.

This script generates branded placeholder PNGs for all 7 expected files
in both `saas` and `non-saas` variants. The hero-default is a gradient
marketing visual; the 6 screenshots are clean section cards labelled
with their feature name plus a "Preview" tag, so the layout is intact
and the intent is obvious until Aron replaces them via the admin UI.

Run once: `python3 tools/generate-landing-placeholders.py`
Commit the generated PNGs.
"""
from __future__ import annotations

from pathlib import Path
from PIL import Image, ImageDraw, ImageFont

REPO = Path(__file__).resolve().parent.parent
OUT = REPO / "public" / "screenshots"

BRAND_BLUE = (30, 64, 175)
BRAND_LIGHT_BLUE = (59, 130, 246)
BRAND_GREEN = (34, 197, 94)
NEUTRAL_BG = (248, 250, 252)
NEUTRAL_CARD = (255, 255, 255)
NEUTRAL_TEXT = (31, 41, 55)
NEUTRAL_MUTED = (107, 114, 128)
ACCENT_TAG_BG = (219, 234, 254)
ACCENT_TAG_FG = (29, 78, 216)

FONT_DISPLAY = "/System/Library/Fonts/Helvetica.ttc"
FONT_FALLBACK = "/System/Library/Fonts/HelveticaNeue.ttc"


def font(size: int, bold: bool = False) -> ImageFont.FreeTypeFont:
    for path in (FONT_DISPLAY, FONT_FALLBACK):
        try:
            idx = 1 if bold else 0
            return ImageFont.truetype(path, size, index=idx)
        except OSError:
            continue
    return ImageFont.load_default()


def linear_gradient(size: tuple[int, int], top: tuple[int, int, int], bottom: tuple[int, int, int]) -> Image.Image:
    w, h = size
    img = Image.new("RGB", size, top)
    px = img.load()
    for y in range(h):
        t = y / max(h - 1, 1)
        r = int(top[0] * (1 - t) + bottom[0] * t)
        g = int(top[1] * (1 - t) + bottom[1] * t)
        b = int(top[2] * (1 - t) + bottom[2] * t)
        for x in range(w):
            px[x, y] = (r, g, b)
    return img


def measure(draw: ImageDraw.ImageDraw, text: str, fnt: ImageFont.FreeTypeFont) -> tuple[int, int]:
    bbox = draw.textbbox((0, 0), text, font=fnt)
    return bbox[2] - bbox[0], bbox[3] - bbox[1]


def hero_default(path: Path, *, headline: str, subline: str) -> None:
    w, h = 1200, 800
    img = linear_gradient((w, h), BRAND_BLUE, BRAND_LIGHT_BLUE)
    draw = ImageDraw.Draw(img, "RGBA")

    for i, opacity in [(1, 28), (2, 22), (3, 16)]:
        r = 280 * i
        draw.ellipse(
            [(w - r // 2, -r // 2 + 80), (w + r // 2, r // 2 + 80)],
            fill=(255, 255, 255, opacity),
        )
    for i, opacity in [(1, 22), (2, 16)]:
        r = 220 * i
        draw.ellipse(
            [(-r // 2, h - r // 2 - 60), (r // 2, h + r // 2 - 60)],
            fill=(255, 255, 255, opacity),
        )

    tag_fnt = font(22, bold=True)
    tag_text = "AFRIPAY HR"
    tw, th = measure(draw, tag_text, tag_fnt)
    pad_x, pad_y = 22, 12
    tag_x = (w - tw) // 2
    tag_y = 240
    draw.rounded_rectangle(
        [(tag_x - pad_x, tag_y - pad_y), (tag_x + tw + pad_x, tag_y + th + pad_y)],
        radius=999,
        fill=(255, 255, 255, 40),
        outline=(255, 255, 255, 110),
        width=2,
    )
    draw.text((tag_x, tag_y), tag_text, font=tag_fnt, fill=(255, 255, 255, 255))

    head_fnt = font(64, bold=True)
    max_head_w = w - 200
    words = headline.split()
    lines: list[str] = []
    line: list[str] = []
    for word in words:
        candidate = " ".join(line + [word])
        cw, _ = measure(draw, candidate, head_fnt)
        if cw <= max_head_w or not line:
            line.append(word)
        else:
            lines.append(" ".join(line))
            line = [word]
    if line:
        lines.append(" ".join(line))

    head_start_y = tag_y + th + pad_y + 40
    line_h = 78
    for i, ln in enumerate(lines):
        lw, _ = measure(draw, ln, head_fnt)
        draw.text(((w - lw) // 2, head_start_y + i * line_h), ln, font=head_fnt, fill=(255, 255, 255, 255))

    sub_fnt = font(26)
    sw, _ = measure(draw, subline, sub_fnt)
    sub_y = head_start_y + len(lines) * line_h + 16
    draw.text(((w - sw) // 2, sub_y), subline, font=sub_fnt, fill=(226, 232, 240, 255))

    img.save(path, "PNG", optimize=True)


def screenshot_card(path: Path, *, title: str, blurb: str) -> None:
    w, h = 1200, 750
    img = Image.new("RGB", (w, h), NEUTRAL_BG)
    draw = ImageDraw.Draw(img, "RGBA")

    card_margin = 80
    card_box = (card_margin, card_margin, w - card_margin, h - card_margin)
    draw.rounded_rectangle(card_box, radius=24, fill=NEUTRAL_CARD, outline=(229, 231, 235, 255), width=2)

    bar_h = 56
    draw.rounded_rectangle(
        [(card_box[0], card_box[1]), (card_box[2], card_box[1] + bar_h)],
        radius=24,
        fill=(243, 244, 246, 255),
    )
    draw.rectangle(
        [(card_box[0], card_box[1] + bar_h - 24), (card_box[2], card_box[1] + bar_h)],
        fill=(243, 244, 246, 255),
    )
    cy = card_box[1] + bar_h // 2
    for i, color in enumerate([(239, 68, 68), (234, 179, 8), (34, 197, 94)]):
        cx = card_box[0] + 28 + i * 26
        draw.ellipse([(cx - 8, cy - 8), (cx + 8, cy + 8)], fill=color)

    tag_fnt = font(18, bold=True)
    tag_text = "PREVIEW"
    tw, th = measure(draw, tag_text, tag_fnt)
    pad_x, pad_y = 14, 8
    tag_x = card_box[2] - tw - pad_x - 32
    tag_y = card_box[1] + (bar_h - th - pad_y * 2) // 2
    draw.rounded_rectangle(
        [(tag_x - pad_x, tag_y), (tag_x + tw + pad_x, tag_y + th + pad_y * 2)],
        radius=999,
        fill=ACCENT_TAG_BG,
    )
    draw.text((tag_x, tag_y + pad_y), tag_text, font=tag_fnt, fill=ACCENT_TAG_FG)

    inner_x = card_box[0] + 64
    inner_y = card_box[1] + bar_h + 70

    title_fnt = font(56, bold=True)
    draw.text((inner_x, inner_y), title, font=title_fnt, fill=NEUTRAL_TEXT)
    tw_title, th_title = measure(draw, title, title_fnt)

    accent_y = inner_y + th_title + 28
    draw.rounded_rectangle([(inner_x, accent_y), (inner_x + 80, accent_y + 6)], radius=3, fill=BRAND_GREEN)

    blurb_fnt = font(22)
    blurb_y = accent_y + 32
    line_h = 32
    max_w = card_box[2] - inner_x - 64
    words = blurb.split()
    line: list[str] = []
    lines: list[str] = []
    for word in words:
        candidate = " ".join(line + [word])
        cw, _ = measure(draw, candidate, blurb_fnt)
        if cw <= max_w:
            line.append(word)
        else:
            if line:
                lines.append(" ".join(line))
            line = [word]
    if line:
        lines.append(" ".join(line))
    for i, ln in enumerate(lines[:3]):
        draw.text((inner_x, blurb_y + i * line_h), ln, font=blurb_fnt, fill=NEUTRAL_MUTED)

    rows_y = blurb_y + min(len(lines), 3) * line_h + 48
    for i in range(4):
        y = rows_y + i * 56
        row_w = max_w - (i % 2) * 80
        draw.rounded_rectangle(
            [(inner_x, y), (inner_x + row_w, y + 40)],
            radius=10,
            fill=(243, 244, 246, 255),
        )
        draw.rounded_rectangle(
            [(inner_x + 10, y + 12), (inner_x + 26, y + 28)],
            radius=4,
            fill=BRAND_LIGHT_BLUE if i % 2 == 0 else BRAND_GREEN,
        )

    foot_fnt = font(16)
    foot_text = "AfriPay HR  •  upload a real screenshot via Landing Page settings to replace"
    fw, fh = measure(draw, foot_text, foot_fnt)
    draw.text(((w - fw) // 2, h - 38), foot_text, font=foot_fnt, fill=(156, 163, 175))

    img.save(path, "PNG", optimize=True)


SECTIONS = [
    ("hero.png", "Dashboard Overview", "Unified view of employee data, payroll status, attendance and KPIs across every branch."),
    ("dashboard.png", "Dashboard", "Real-time KPIs across headcount, payroll, attendance and pending approvals."),
    ("employee-management.png", "Employee Management", "Centralised profiles with personal details, documents, contracts and job history."),
    ("payroll-payslip.png", "Payroll & Payslips", "Automated PAYE, NAPSA and NHIMA calculations with downloadable payslips."),
    ("leave.png", "Leave Management", "Apply, approve and track leave with policy-aware workflows."),
    ("attendance.png", "Attendance Tracking", "Check-ins, shifts and overtime logs with biometric and manual capture."),
    ("recruitment.png", "Recruitment & Onboarding", "Applicant tracking, interview pipelines and digital onboarding."),
]

HERO_VARIANTS = {
    "saas": ("Cloud HR and Payroll for African SMEs", "Process PAYE, NHIMA, NAPSA and SDL — without the spreadsheets."),
    "non-saas": ("AfriPay HR", "Modern HR and payroll, built for your team."),
}


def main() -> None:
    for variant, (headline, subline) in HERO_VARIANTS.items():
        out_dir = OUT / variant
        out_dir.mkdir(parents=True, exist_ok=True)

        hero_default(out_dir / "hero-default.png", headline=headline, subline=subline)
        print(f"  wrote {out_dir / 'hero-default.png'}")

        for filename, title, blurb in SECTIONS:
            screenshot_card(out_dir / filename, title=title, blurb=blurb)
            print(f"  wrote {out_dir / filename}")

    flat_dir = OUT
    flat_dir.mkdir(parents=True, exist_ok=True)
    for filename, title, blurb in SECTIONS:
        screenshot_card(flat_dir / filename, title=title, blurb=blurb)
        print(f"  wrote {flat_dir / filename}")


if __name__ == "__main__":
    main()
