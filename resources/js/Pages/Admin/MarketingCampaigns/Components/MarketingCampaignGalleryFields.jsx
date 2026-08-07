import { Field, Label } from "@/Components/Catalyst/fieldset";
import { Input } from "@/Components/Catalyst/input";
import { Text } from "@/Components/Catalyst/text";
import { Button } from "@/Components/Catalyst/button";
import {
	ChevronDownIcon,
	ChevronUpIcon,
	XMarkIcon,
} from "@heroicons/react/16/solid";

const MAX_ITEMS = 6;

function previewUrl(item) {
	if (item.preview) return item.preview;
	if (item.kind === "external") return item.url;
	if (item.kind === "existing") return item.url;
	return null;
}

export default function MarketingCampaignGalleryFields({
	items = [],
	onChange,
	errors = {},
}) {
	const addUpload = (file) => {
		if (!file || items.length >= MAX_ITEMS) return;
		onChange([
			...items,
			{
				kind: "upload",
				key: `upload-${Date.now()}`,
				file,
				preview: URL.createObjectURL(file),
				alt: "",
			},
		]);
	};

	const addExternal = () => {
		if (items.length >= MAX_ITEMS) return;
		onChange([
			...items,
			{
				kind: "external",
				key: `external-${Date.now()}`,
				url: "",
				alt: "",
			},
		]);
	};

	const updateItem = (index, patch) => {
		const next = [...items];
		next[index] = { ...next[index], ...patch };
		onChange(next);
	};

	const removeItem = (index) => {
		onChange(items.filter((_, itemIndex) => itemIndex !== index));
	};

	const moveItem = (index, direction) => {
		const next = [...items];
		const target = index + direction;
		if (target < 0 || target >= next.length) return;
		[next[index], next[target]] = [next[target], next[index]];
		onChange(next);
	};

	return (
		<div className="space-y-4">
			<Text className="text-sm text-zinc-500">
				Hasta {MAX_ITEMS} imágenes. Puedes mezclar archivos subidos y
				URLs HTTPS externas.
			</Text>

			<div className="flex flex-wrap gap-2">
				<Field>
					<Label>Agregar archivo</Label>
					<Input
						type="file"
						accept="image/jpeg,image/png,image/webp"
						disabled={items.length >= MAX_ITEMS}
						onChange={(e) => {
							const file = e.target.files?.[0];
							addUpload(file);
							e.target.value = "";
						}}
					/>
				</Field>
				<div className="flex items-end">
					<Button
						type="button"
						outline
						disabled={items.length >= MAX_ITEMS}
						onClick={addExternal}
					>
						Agregar URL externa
					</Button>
				</div>
			</div>

			{(errors.gallery_items || errors.gallery_uploads) && (
				<Text className="text-sm text-red-600 dark:text-red-500">
					{errors.gallery_items || errors.gallery_uploads}
				</Text>
			)}

			{items.length === 0 ? (
				<Text className="text-sm text-zinc-500">
					Sin imágenes en la galería.
				</Text>
			) : (
				<ul className="space-y-3">
					{items.map((item, index) => {
						const imageUrl = previewUrl(item);

						return (
							<li
								key={item.key || `${item.kind}-${item.id || index}`}
								className="rounded-xl border border-zinc-200 p-3 dark:border-zinc-700"
							>
								<div className="flex flex-wrap gap-4">
									{imageUrl ? (
										<img
											src={imageUrl}
											alt={item.alt || "Vista previa galería"}
											className="h-24 w-32 rounded-lg object-cover"
										/>
									) : (
										<div className="flex h-24 w-32 items-center justify-center rounded-lg bg-zinc-100 text-sm text-zinc-500 dark:bg-zinc-800">
											Sin preview
										</div>
									)}

									<div className="min-w-0 flex-1 space-y-3">
										<Text className="text-sm font-medium">
											{item.kind === "existing"
												? "Imagen existente"
												: item.kind === "upload"
													? "Nueva imagen"
													: "URL externa"}
										</Text>

										{item.kind === "external" && (
											<Field>
												<Label>URL HTTPS</Label>
												<Input
													value={item.url || ""}
													onChange={(e) =>
														updateItem(index, {
															url: e.target.value,
														})
													}
													placeholder="https://ejemplo.com/imagen.jpg"
												/>
											</Field>
										)}

										<Field>
											<Label>Texto alternativo</Label>
											<Input
												value={item.alt || ""}
												onChange={(e) =>
													updateItem(index, {
														alt: e.target.value,
													})
												}
												maxLength={180}
											/>
										</Field>
									</div>

									<div className="flex items-start gap-1">
										<Button
											type="button"
											plain
											disabled={index === 0}
											onClick={() => moveItem(index, -1)}
										>
											<ChevronUpIcon className="size-4" />
										</Button>
										<Button
											type="button"
											plain
											disabled={index === items.length - 1}
											onClick={() => moveItem(index, 1)}
										>
											<ChevronDownIcon className="size-4" />
										</Button>
										<Button
											type="button"
											plain
											onClick={() => removeItem(index)}
										>
											<XMarkIcon className="size-4" />
										</Button>
									</div>
								</div>
							</li>
						);
					})}
				</ul>
			)}

			<Text className="text-sm text-zinc-500">
				El orden aquí define el orden en la landing pública.
			</Text>
		</div>
	);
}
