# Design System Strategy: The Luminous Interface

## 1. Overview & Creative North Star: "The Digital Pulse"
This design system moves away from the rigid, boxed-in layouts of traditional chat applications. Our Creative North Star is **"The Digital Pulse"**—a concept where the interface feels like a living, breathing entity rather than a static tool. 

We achieve a "High-End Editorial" feel by leaning into the tension between high-contrast typography and soft, ethereal surfaces. Instead of a grid of messages, we treat each conversation as a curated narrative. By utilizing intentional asymmetry (e.g., varying message bubble widths and generous staggered vertical spacing), we create a sense of flow and human movement. The "template" look is eradicated through the total removal of structural lines, replaced by a sophisticated hierarchy of light and depth.

---

### 2. Colors: Tonal Depth over Borders
The palette is a sophisticated interplay of `#fff7fb` (Background) and `#952cb1` (Primary). 

*   **The "No-Line" Rule:** Under no circumstances shall a 1px solid border be used to separate sections. We define boundaries exclusively through background shifts. For example, the chat input area sits on `surface-container-low`, resting upon the `surface` background.
*   **Surface Hierarchy & Nesting:** We treat the UI as a series of stacked, semi-translucent layers. 
    *   **Level 0 (Base):** `surface` (#fff7fb)
    *   **Level 1 (Navigation/Status):** `surface-container-low` (#ffeffc)
    *   **Level 2 (Active Cards/Input):** `surface-container-high` (#fedeff)
*   **The "Glass & Gradient" Rule:** Floating elements, such as the "New Message" FAB, must utilize a backdrop-blur (12px–20px) combined with a semi-transparent `primary` tint. 
*   **Signature Textures:** Linear gradients are our "visual soul." Use a 45-degree transition from `primary` (#952cb1) to `primary-container` (#f1a6ff) for primary call-to-actions to provide a soft, internal glow that flat colors cannot replicate.

---

### 3. Typography: Editorial Precision
We use **Plus Jakarta Sans** across the board for its geometric yet friendly modernism.

*   **Display & Headline:** Use `display-md` (2.75rem) for empty-state greetings (e.g., "Start something new."). The tight letter-spacing and large scale create an authoritative, premium feel.
*   **The Title/Body Relationship:** Use `title-md` (1.125rem) for contact names in a conversation list, paired with `body-sm` (#75547a) for the message preview. This high-contrast ratio ensures the user's eye is pulled to the most important information first.
*   **Labels:** `label-sm` (0.6875rem) is reserved for timestamps and status indicators. To ensure it feels premium, use `on-surface-variant` with a 0.05rem letter-spacing.

---

### 4. Elevation & Depth: The Layering Principle
We reject the "drop shadow" of 2014. Depth in this system is organic and environmental.

*   **Tonal Layering:** To lift a chat bubble, we do not use a shadow. We place a `secondary-container` (#fed6ff) bubble on a `surface` background. The subtle shift in hue creates a natural, soft lift.
*   **Ambient Shadows:** When an element must float (e.g., a modal or profile card), use a shadow color tinted with `on-surface` (#45274b) at 4% opacity with a 40px blur. This mimics real-world ambient occlusion.
*   **The "Ghost Border" Fallback:** If high-contrast accessibility is required, use `outline-variant` (#cca5d0) at **15% opacity**. This creates a suggestion of a container without breaking the "Luminous" aesthetic.
*   **Glassmorphism:** Use `surface-container-lowest` at 80% opacity with a 15px backdrop-blur for header bars. This allows the vibrant purple of the chat bubbles to "ghost" through the header as the user scrolls, maintaining a sense of spatial awareness.

---

### 5. Components

*   **Chat Bubbles:**
    *   **User:** Gradient of `primary` to `primary-dim`. Corner radius: `md` (1.5rem), but the bottom-right corner is `sm` (0.5rem) to indicate direction.
    *   **Recipient:** `surface-container-highest`. Corner radius: `md`, with bottom-left at `sm`.
    *   *Constraint:* No dividers between messages. Use Spacing `2` (0.7rem) for grouped messages and `4` (1.4rem) between different speakers.
*   **Buttons:** 
    *   **Primary:** Full-rounded (`full`). Uses the signature gradient. Padding: `3.5` (vertical) by `6` (horizontal).
    *   **Tertiary:** No background. Use `primary` text with a subtle `primary-fixed-dim` hover state.
*   **Input Fields:** 
    *   A pill-shaped container (`full`) using `surface-container-high`. Text is `body-lg`. The "Send" icon is a floating `primary` circle within the container.
*   **Conversation List:** 
    *   Forbid the use of divider lines. Use Spacing `8` (2.75rem) of vertical white space to separate chat threads. This creates an editorial "breathing room."
*   **Avatars:**
    *   Always use a soft `lg` (2rem) corner radius rather than a circle. This feels more modern and less like a standard social media template.

---

### 6. Do's and Don'ts

*   **DO:** Use intentional asymmetry. If a user sends a short message, don't let the bubble span the whole screen. Let the whitespace define the rhythm.
*   **DO:** Use the `tertiary` (#ac2d5e) color sparingly for "hot" interactions, like heart reactions or urgent alerts.
*   **DON'T:** Use pure black (#000000). Use `on-background` (#45274b) for all "black" text to maintain the soft, plum-tinted harmony.
*   **DON'T:** Ever use a solid 1px line. If you feel the need to separate, use a background color shift or increase the spacing.
*   **DO:** Ensure `on-primary` (#fff7fb) is used for text over gradients to maintain a 7:1 contrast ratio for accessibility.