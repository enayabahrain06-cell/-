# @ahl/certificates-react

React 18/19 screens for the [`ahl/laravel-certificates`](../certificates/README.md) API: the
certificates list with bulk approval, filters and a details drawer (PDF preview, history, actions), the
issue / edit / revoke dialogs, the template editor (one card per language, placeholders, signatures,
design), a recipient tab (profile page, self-service page) and the public QR verification page.
Arabic (RTL) and English (LTR) texts are included.

Peer dependencies: `react`, `react-router-dom`, `@tanstack/react-query`, `react-i18next` / `i18next`,
`axios`. Styling is Tailwind v4 utility classes.

## Install

```bash
npm install @ahl/certificates-react            # or "file:../packages/certificates-react" in a monorepo
```

The package ships TypeScript source. In the host:

```css
/* index.css */
@import "tailwindcss";
@import "@ahl/certificates-react/theme.css";   /* skip when your @theme already defines brand, gold, ink, danger, page, paper, info */
@source "../node_modules/@ahl/certificates-react/src";
```

For a `file:` link also set `resolve.preserveSymlinks: true` and
`optimizeDeps.exclude: ['@ahl/certificates-react']` in Vite, and `"preserveSymlinks": true` in
tsconfig, so the package's imports resolve from the host's `node_modules`.

## Use

```tsx
import { CertificatesProvider, CertificatesPage, RecipientCertificates, VerifyCertificatePage, registerCertificatesI18n } from '@ahl/certificates-react'

registerCertificatesI18n(i18n)          // host keys in the "certificates" namespace win

<QueryClientProvider client={queryClient}>
  <CertificatesProvider
    http={axiosInstance}                 // baseURL ends in /api; auth header and Accept-Language set by you
    can={(p) => user.permissions.includes(p)}
    RecipientPicker={EmployeePicker}     // ({ label, onPick(recipient) }) for the issue dialog
    RecipientCard={EmployeeCard}         // ({ recipient, size }) in lists and chips
    recipientHref={(r) => `/employees/${r.id}`}
    ui={myDesignSystem}                  // optional: Partial<UiKit>, your own Button, Modal, Notice …
  >
    <RouterProvider router={router} />
  </CertificatesProvider>
</QueryClientProvider>

// routes
{ path: '/certificates', element: <CertificatesPage /> }          // ?view=templates for template managers
{ path: '/verify/:token', element: <VerifyCertificatePage /> }    // public, the QR target
<RecipientCertificates type="employee" id={employee.id} summaryTypes={[{ type: 'completion' }]} />
```

`ui` accepts any subset of `UiKit` (`Badge`, `PrimaryButton`, `SecondaryButton`, `TextInput`,
`TextArea` (forward the ref), `SelectField`, `SearchInput`, `Segmented`, `Notice`, `LoadingState`,
`ErrorState`, `EmptyCard`, `Card`, `FilterBar`, `TableWrap`, `Pagination`, `Modal`, `PageHeader`,
`PublicLayout`, `Spinner`, `Icon`, `Divider`, `classes`); the rest falls back to `defaultUi`.
`onChanged` runs after every change, to refresh host screens that show certificate counts.

Permissions used for buttons: `certificates.issue` and `certificates.templates` (the API has the final say).
