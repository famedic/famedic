export function resolveLinkBrandValue(data, collections = []) {
	const payload = data?.target_payload || {};

	switch (data?.target_type) {
		case "brand":
			return payload.brand || null;
		case "category":
			return payload.brand || null;
		case "product":
			return payload.brand || null;
		case "collection": {
			const collectionId = payload.marketing_campaign_collection_id;
			if (!collectionId) return null;
			const collection = collections.find(
				(item) => Number(item.id) === Number(collectionId),
			);
			return collection?.laboratory_brand || null;
		}
		default:
			return null;
	}
}

export function buildGalleryPayload(items = []) {
	const uploads = [];
	const payload = items.map((item) => {
		if (item.kind === "existing") {
			return {
				kind: "existing",
				id: item.id,
				alt: item.alt || null,
			};
		}

		if (item.kind === "upload") {
			const uploadIndex = uploads.length;
			uploads.push(item.file);
			return {
				kind: "upload",
				upload_index: uploadIndex,
				alt: item.alt || null,
			};
		}

		return {
			kind: "external",
			url: item.url,
			alt: item.alt || null,
		};
	});

	return { gallery_items: payload, gallery_uploads: uploads };
}
