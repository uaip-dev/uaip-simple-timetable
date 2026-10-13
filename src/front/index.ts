// Front-end behaviour of UAIP Simple Timetable. The server-rendered HTML already works
// without JavaScript (links and a GET form). This upgrades it:
//  - previous/next/today, view switcher and month "+N more" update in place via the REST
//    `render` endpoint instead of reloading the page;
//  - filter dropdowns apply immediately;
//  - clicking an event opens a details popover (a native <dialog>) instead of leaving.

interface State {
  attrs: Record<string, string>;
  instance: number;
}

const ROOT = ".uaip-tt";
const QUERY = "uaip_tt";
const pending = new Map<number, AbortController>();

document.documentElement.classList.add("uaip-tt-js");

function stateOf(root: HTMLElement): State | null {
  try {
    return JSON.parse(root.dataset.uaipTt || "") as State;
  } catch {
    return null;
  }
}

function plainClick(e: MouseEvent): boolean {
  return e.button === 0 && !e.metaKey && !e.ctrlKey && !e.shiftKey && !e.altKey;
}

/** Re-renders one timetable with some attributes changed; runs `fallback` (the no-JS way) on error. */
async function update(root: HTMLElement, change: Record<string, string>, fallback: () => void, focusId?: string): Promise<void> {
  const state = stateOf(root);
  const rest = root.dataset.rest;
  if (!state || !rest) {
    fallback();
    return;
  }
  const attrs = { ...state.attrs, ...change };
  const url = new URL(rest, location.href);
  for (const [key, value] of Object.entries(attrs)) url.searchParams.set(key, value);
  url.searchParams.set("instance", String(state.instance));
  url.searchParams.set("base", location.href.split("#")[0]);

  pending.get(state.instance)?.abort();
  const controller = new AbortController();
  pending.set(state.instance, controller);
  root.setAttribute("aria-busy", "true");

  try {
    const response = await fetch(url, { headers: { Accept: "application/json" }, signal: controller.signal });
    if (!response.ok) throw new Error(String(response.status));
    const body = (await response.json()) as { html?: string };
    const template = document.createElement("template");
    template.innerHTML = (body.html || "").trim();
    const next = template.content.firstElementChild as HTMLElement | null;
    if (!next || !next.matches(ROOT)) throw new Error("bad html");
    root.replaceWith(next);
    rememberInUrl(next);

    const heading = next.querySelector<HTMLElement>(".uaip-tt__heading");
    const status = next.querySelector<HTMLElement>(".uaip-tt__status");
    if (status && heading) status.textContent = heading.textContent?.trim() || "";
    const focusTarget = (focusId && next.querySelector<HTMLElement>(`#${CSS.escape(focusId)}`)) || heading;
    focusTarget?.focus({ preventScroll: !!focusId });
  } catch (error) {
    if ((error as Error).name === "AbortError") return;
    fallback();
  } finally {
    if (pending.get(state.instance) === controller) pending.delete(state.instance);
    root.removeAttribute("aria-busy");
  }
}

/** Keeps the shown period and filters in the address bar, so reload and sharing work. */
function rememberInUrl(root: HTMLElement): void {
  const state = stateOf(root);
  if (!state) return;
  const url = new URL(location.href);
  for (const key of [...url.searchParams.keys()]) if (key === QUERY || key.startsWith(QUERY + "_")) url.searchParams.delete(key);
  url.searchParams.set(QUERY, String(state.instance));
  url.searchParams.set(`${QUERY}_view`, state.attrs.view || "");
  url.searchParams.set(`${QUERY}_date`, state.attrs.date || "");
  const fixed = (state.attrs.fixed || "").split(",");
  for (const key of ["category", "location", "instructor"]) {
    if (!fixed.includes(key) && state.attrs[key]) url.searchParams.set(`${QUERY}_${key}`, state.attrs[key]);
  }
  history.replaceState(history.state, "", url);
}

/* ---------- Event details popover ---------- */

let dialog: HTMLDialogElement | null = null;

function getDialog(): HTMLDialogElement {
  if (dialog) return dialog;
  dialog = document.createElement("dialog");
  dialog.className = "uaip-tt-dialog";
  dialog.setAttribute("aria-labelledby", "uaip-tt-dialog-title");
  dialog.innerHTML =
    '<div class="uaip-tt-dialog__inner"><button type="button" class="uaip-tt-dialog__close" value="close">' +
    '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path d="M12 10.6 6.4 5 5 6.4l5.6 5.6L5 17.6 6.4 19l5.6-5.6 5.6 5.6 1.4-1.4-5.6-5.6L19 6.4 17.6 5z" fill="currentColor"/></svg>' +
    '</button><div class="uaip-tt-dialog__body"></div></div>';
  const close = dialog.querySelector<HTMLButtonElement>(".uaip-tt-dialog__close")!;
  close.addEventListener("click", () => dialog?.close());
  // Click on the backdrop closes.
  dialog.addEventListener("click", (e) => {
    if (e.target === dialog) dialog?.close();
  });
  document.body.append(dialog);
  return dialog;
}

function openDetails(link: HTMLAnchorElement, root: HTMLElement): boolean {
  const source = link.dataset.details ? document.getElementById(link.dataset.details) : null;
  if (!source || typeof HTMLDialogElement === "undefined") return false;
  const d = getDialog();
  const body = d.querySelector<HTMLElement>(".uaip-tt-dialog__body")!;
  body.replaceChildren(...Array.from(source.cloneNode(true).childNodes));
  body.querySelector(".uaip-tt-details__title")?.setAttribute("id", "uaip-tt-dialog-title");
  const close = d.querySelector<HTMLButtonElement>(".uaip-tt-dialog__close")!;
  close.setAttribute("aria-label", root.dataset.closeLabel || "Close");

  // Carry the timetable's colour scheme and the event colour into the dialog.
  d.className = "uaip-tt-dialog " + Array.from(root.classList).filter((c) => c.startsWith("uaip-tt--scheme-")).join(" ");
  const item = link.closest<HTMLElement>(".uaip-tt-event");
  d.style.setProperty("--uaip-tt-bg", item?.style.getPropertyValue("--uaip-tt-bg") || "");
  d.style.setProperty("--uaip-tt-fg", item?.style.getPropertyValue("--uaip-tt-fg") || "");
  d.showModal();
  return true;
}

/* ---------- Event delegation ---------- */

document.addEventListener("click", (e) => {
  const target = e.target as Element | null;
  if (!target || !plainClick(e)) return;
  const root = target.closest<HTMLElement>(ROOT);
  if (!root) return;

  const nav = target.closest<HTMLAnchorElement>("a[data-nav]");
  if (nav) {
    let change: Record<string, string> = {};
    try {
      change = JSON.parse(nav.dataset.nav || "{}");
    } catch {
      return;
    }
    e.preventDefault();
    void update(root, change, () => (location.href = nav.href));
    return;
  }

  const event = target.closest<HTMLAnchorElement>("a.uaip-tt-event__link");
  if (event && openDetails(event, root)) e.preventDefault();
});

document.addEventListener("change", (e) => {
  const select = e.target as HTMLSelectElement | null;
  if (!select || select.tagName !== "SELECT" || !select.dataset.filter) return;
  const root = select.closest<HTMLElement>(ROOT);
  if (!root) return;
  void update(root, { [select.dataset.filter]: select.value }, () => select.form?.submit(), select.id);
});
