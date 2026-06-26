import { useCallback, useEffect, useRef, useState } from "react";
import axios from "axios";

export default function useMembershipTab(tab) {
	const [cache, setCache] = useState({});
	const [loadingTab, setLoadingTab] = useState(null);
	const [error, setError] = useState(null);
	const cacheRef = useRef(cache);

	cacheRef.current = cache;

	const loadTab = useCallback(async (tabKey) => {
		if (!tabKey || tabKey === "resumen" || cacheRef.current[tabKey]) {
			return;
		}

		setLoadingTab(tabKey);
		setError(null);

		try {
			const { data } = await axios.get(route("membership.tab", tabKey));
			setCache((current) => ({ ...current, [tabKey]: data }));
		} catch (requestError) {
			setError(requestError);
		} finally {
			setLoadingTab(null);
		}
	}, []);

	useEffect(() => {
		loadTab(tab);
	}, [tab, loadTab]);

	return {
		data: cache[tab] ?? null,
		loading: loadingTab === tab,
		error,
		reload: () => loadTab(tab),
	};
}
