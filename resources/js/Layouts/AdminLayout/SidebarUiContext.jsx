import {
	createContext,
	useCallback,
	useContext,
	useEffect,
	useMemo,
	useState,
} from "react";

const STORAGE_KEY = "famedic-admin-sidebar-collapsed";

const AdminSidebarUiContext = createContext({
	collapsed: false,
	rail: false,
	setCollapsed: () => {},
	toggle: () => {},
});

function useIsLgUp() {
	const [matches, setMatches] = useState(false);

	useEffect(() => {
		if (typeof window === "undefined" || !window.matchMedia) {
			return undefined;
		}
		const media = window.matchMedia("(min-width: 1024px)");
		const update = () => setMatches(media.matches);
		update();
		media.addEventListener("change", update);
		return () => media.removeEventListener("change", update);
	}, []);

	return matches;
}

export function AdminSidebarUiProvider({ children }) {
	const [collapsed, setCollapsedState] = useState(false);
	const isLgUp = useIsLgUp();

	useEffect(() => {
		try {
			setCollapsedState(localStorage.getItem(STORAGE_KEY) === "1");
		} catch {
			// ignore
		}
	}, []);

	const setCollapsed = useCallback((value) => {
		setCollapsedState(Boolean(value));
		try {
			localStorage.setItem(STORAGE_KEY, value ? "1" : "0");
		} catch {
			// ignore
		}
	}, []);

	const toggle = useCallback(() => {
		setCollapsedState((prev) => {
			const next = !prev;
			try {
				localStorage.setItem(STORAGE_KEY, next ? "1" : "0");
			} catch {
				// ignore
			}
			return next;
		});
	}, []);

	const rail = collapsed && isLgUp;

	const value = useMemo(
		() => ({ collapsed, rail, setCollapsed, toggle }),
		[collapsed, rail, setCollapsed, toggle],
	);

	return (
		<AdminSidebarUiContext.Provider value={value}>
			{children}
		</AdminSidebarUiContext.Provider>
	);
}

export function useAdminSidebarUi() {
	return useContext(AdminSidebarUiContext);
}
