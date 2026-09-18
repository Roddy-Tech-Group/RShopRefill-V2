# RshopRefills V2 - Working Rules and Design System

Verified against this repository on 2026-09-17. This guide applies to RshopRefills V2, the digital commerce and wallet application. Keep this filename so existing references continue to resolve.

The current stack is **Laravel 12 + Blade + Livewire 4 + Alpine.js + Tailwind CSS 4 + GSAP**. Exact dependency versions and the dated local environment snapshot are documented in [README.md](README.md#tech-stack). Check the lockfiles and implementation again when upgrading or changing environments.

## 1. How to work with Roddy

1. **Report before editing.** Inspect the relevant code and explain the proposed scope. Ask before edits that have not already been authorized. Once Roddy approves a concrete change or directly requests the reported work, complete that scope without repeatedly asking.
2. **Verify instead of guessing.** Confirm paths, component consumers, database fields, settings, and behavior from the repository. Clearly separate verified facts, local configuration, and assumptions.
3. **Keep changes scoped.** Preserve existing work, animations, and unrelated behavior. Check all consumers before changing a shared component. Report any required scope expansion before making it.
4. **Respect the frontend/backend role split.** Follow the team workflow in [ProjectMemory.md](ProjectMemory.md#team--workflow-rules). Frontend tasks bind to existing backend contracts; backend changes require explicit authorization.
5. **Roddy runs state-changing operational commands.** Provide copyable commands for builds, migrations, Git mutations, and deployments unless he has authorized execution. Read-only inspection is allowed.
6. **Stay within installed tools.** Use the existing Blade/Flux components, Alpine interactions, Tailwind styles, GSAP animations, and Lottie loader. Ask before adding dependencies or replacing the frontend or animation architecture.
7. **Documentation only when requested.** Update existing guides when possible and keep claims tied to source files.
8. **No em dashes in new code, comments, copy, or documentation.** Use a hyphen, colon, or separate sentence. Do not append a `Co-Authored-By: Claude` commit trailer.
9. **Keep reports concise.** State what changed, how it was checked, and any remaining limitation.

## 2. Stack and application structure

| Area | Implementation |
| --- | --- |
| Backend | PHP `^8.2`, Laravel 12, Eloquent models, controllers, domain services, jobs, and events |
| Rendering | Blade templates in `resources/views`, Livewire 4 and Volt components |
| UI components | Existing Blade components and Flux UI 2 |
| Browser state | Alpine.js supplied by Livewire, plus plain JavaScript in `resources/js` |
| Styling | Tailwind CSS 4 through `@tailwindcss/vite`, custom CSS and `@theme` tokens in `resources/css/app.css` |
| Motion | GSAP with ScrollTrigger and MotionPathPlugin, inline SVG scenes, Lottie, CSS transitions, and existing carousel code |
| Build | Vite 6 with `laravel-vite-plugin`; entries are `resources/css/app.css` and `resources/js/app.js` |
| Data and queues | Environment-selected Laravel drivers; Redis/Horizon configuration exists, while the inspected local queue uses `sync` |
| Verification | PHPUnit, Laravel Pint, Playwright tooling, and GitHub Actions |

Business logic lives under `app/Domain`; HTTP entry points are in `app/Http` and `routes`. Class-based Livewire components are in `app/Livewire`, with Blade/Volt views under `resources/views/livewire`. Middleware and route registration use `bootstrap/app.php`.

Use the existing named routes and `wire:navigate` behavior where the surrounding component uses it. Preserve `livewire:navigated` initialization and `livewire:navigating` cleanup. Consult `composer.json`, `composer.lock`, `package.json`, and `package-lock.json` before changing stack guidance.

Local configuration is a dated observation, not a production deployment specification. A configured service name does not establish that its package, credentials, worker, or external service is running.

## 3. Typography and tokens

- **Font:** DM Sans, loaded from Google Fonts as a variable font in `resources/css/app.css`. The current `--font-sans` is `'DM Sans', sans-serif`; `--font-weight-base` is `399`.
- **Brand blue:** `--color-brand: #0044FF`; hover `#0036cc`. Existing components also use Tailwind `blue-600` and `blue-700` for controls.
- **Neutral tints:** `--color-brand-50` and `--color-blue-50` are `#f4f6f5`; `--color-brand-100` and `--color-blue-100` are `#e8ecea`. Pale blue, sky, and indigo utilities have project overrides, so inspect the stylesheet instead of assuming Tailwind defaults.
- **Storefront ink:** the light-theme rules map strong text to `#0c1a36` and muted text to `#4a5a72`. Follow the existing text classes and theme overrides.
- Read existing component styles before introducing a token. Keep shared tokens in `resources/css/app.css`.

## 4. Surfaces, spacing, and shape

- **Approved homepage palette:** page background `#f4f6f5`; white `#ffffff` cards and FAQ panel.
- **Approved page/panel relationship:** a white page uses a `#f4f6f5` panel; a `#f4f6f5` page uses a white panel. Apply this in the relevant page context and inspect shared component consumers before changing defaults.
- Keep a visible gap between the gift-card FAQ panel and the footer. Preserve the existing page wrapper spacing.
- Geometry depends on the component. Existing panels and cards include 12px and 20px corners; approved pill buttons and circular controls use `rounded-full`. Follow the relevant existing component and the user's latest reference.
- Preserve existing thin rings, quiet shadows, focus states, and hover treatment. Check the actual footer component and theme rules for its surfaces.
- Color or spacing work should remain scoped to the approved page or component. Existing gift-card and eSIM designs have already been accepted by the user.

## 5. Buttons and carousel controls

- The homepage review header is the current reference for matching section controls: a 32px-high (`h-8`) `See more` pill, 32px circular arrow buttons, 20px arrow SVGs, white fill, thin gray ring, and `shadow-sm`.
- Functional carousel arrows and dropdown/accordion chevrons are part of the interface. Keep their labels, click handlers, disabled states, and keyboard behavior.
- The shared brand-row and eSIM explore-row `See all` links are currently 40px-high pills. Brand-review arrows also have a different shape, and some other rows use 16px icons. These are existing differences; a site-wide consistency change has been proposed but has not been applied.
- Inspect `home/customer-reviews.blade.php`, `home/brand-row.blade.php`, `home/explore-row.blade.php`, `shop/brand-reviews.blade.php`, and `shop/category-section.blade.php` under `resources/views/components` before changing these controls.
- Preserve mobile visibility rules and the current carousel implementation when editing appearance. Keep visible keyboard focus and an accessible name for icon-only controls.

## 6. Themes

- Dark mode uses the `.dark` class on `<html>` and the existing light/dark/auto theme controls. Additional dark-theme settings and surface overrides already exist.
- Tailwind's dark variant is defined with `:where(.dark, .dark *)` to keep specificity predictable. Preserve the existing theme engine, stored preferences, and initial-paint behavior.
- Use the existing `--ground`, `--ash`, and related CSS variables for dark surfaces. Check the current stylesheet rather than copying old palette values from session history.
- Verify relevant light and dark states after a shared surface change. Preserve the reduced-motion behavior of theme transitions.

## 7. Motion and illustrations

- GSAP is already installed. `resources/js/app.js` dynamically loads GSAP, ScrollTrigger, and MotionPathPlugin and contains the existing animation initialization.
- Inline SVG illustrations live in `resources/views/components/illo.blade.php`; their matching scene definitions live in the `ILLOS` registry in `resources/js/app.js`.
- Preserve scene-scoped GSAP contexts, entrance timing, hover replay, and cleanup. When replacing an illustration, update the matching scene and selectors together.
- The `delivery` scene illustrates the completed mobile order view from `resources/views/shop/order.blade.php`. It uses public catalog artwork and masked credentials; the illustration must never read a customer's order or credential.
- The current illustration loader skips animation when `prefers-reduced-motion: reduce` is enabled. Keep the SVG's static final state visible and useful.
- Reuse the existing Lottie loader for Lottie assets. Preserve each carousel's current transform or native-scroll implementation and reduced-motion behavior.
- Prefer transform and opacity for movement where practical. Check smooth entrance, replay, resizing, and navigation behavior after an animation change.

## 8. Components, dialogs, and responsive behavior

- Reuse Blade components under `resources/views/components` and existing Flux components. Keep component interfaces compatible with their current callers.
- The shared confirmation dialog is `<x-confirm-modal />`. It uses the Alpine `confirmModal()` state in `resources/js/app.js` and the existing `data-confirm` / `data-confirm-*` attributes on forms or buttons.
- Preserve dialog labels, focus behavior, Escape handling, click-to-close behavior, and the existing Alpine transitions.
- Use `x-cloak` where the surrounding Alpine component requires it. Preserve initialization during Livewire navigation.
- Keep the existing viewport-width measurement, `scrollbar-gutter: stable`, responsive breakpoints, safe-area spacing, and touch scrolling. Inspect the rendered mobile view when changing a shared layout.

## 9. Data and customer-facing copy

- Catalog values, prices, counts, contact details, and service availability come from the existing models, settings, configuration, or provider contracts. Use a neutral placeholder when required source data is missing.
- Use existing translation keys in `lang` where available. Storefront copy supports English and French; inspect `config/localization.php` for currently offered languages.
- Use existing brand artwork and helpers from `App\Models\Product` and the brand configuration files.
- Keep internal supplier and gateway identifiers out of customer-facing copy unless the existing customer flow explicitly requires the provider name.
- Preserve backend validation, named routes, forms, and data bindings during visual work.

## 10. Verification and documentation

- Verify the smallest affected scope. Backend or data-selection logic needs relevant PHPUnit coverage; visual changes need rendered checks that address the actual risk.
- Run Pint on explicitly changed PHP files when applicable. Preserve unrelated files in an already dirty working tree.
- For animation work, check the entrance, hover replay, mobile layout, and reduced-motion state. For documentation-only work, verify claims, local links, and the diff.
- Record whether a statement comes from a dependency requirement, locked version, source default, local configuration, or verified deployment. Keep credentials and secrets out of documentation.
- Preserve historical session notes as history. Current code, explicit user instructions, this guide, and the verified README stack take precedence over older snapshots.

## 11. Reference files

- [README.md](README.md): dependency snapshot, setup, runtime, and testing.
- [CLAUDE.md](CLAUDE.md): Laravel and agent guidance.
- [ProjectMemory.md](ProjectMemory.md): team workflow and dated session history.
- [resources/css/app.css](resources/css/app.css): font, colors, theme tokens, and shared CSS.
- [resources/js/app.js](resources/js/app.js): Alpine factories, GSAP scenes, carousel and dialog behavior.
- [resources/views/components](resources/views/components): shared Blade UI components.
- [vite.config.js](vite.config.js): frontend entries and build configuration.
