import { Field, Label, ErrorMessage } from "@/Components/Catalyst/fieldset";
import { Text } from "@/Components/Catalyst/text";
import { Button } from "@/Components/Catalyst/button";
import {
	Listbox,
	ListboxOption,
	ListboxLabel,
} from "@/Components/Catalyst/listbox";
import {
	ChevronDownIcon,
	ChevronUpIcon,
	XMarkIcon,
} from "@heroicons/react/16/solid";

export default function MarketingCampaignCategorySelector({
	categories = [],
	selectedItems = [],
	onChange,
	maxItems = 8,
	error,
}) {
	const selectedIds = new Set(selectedItems.map((item) => Number(item.id)));
	const available = categories.filter(
		(category) => !selectedIds.has(Number(category.id)),
	);

	const addCategory = (categoryId) => {
		if (!categoryId || selectedItems.length >= maxItems) return;
		const category = categories.find(
			(item) => Number(item.id) === Number(categoryId),
		);
		if (!category) return;
		onChange([...selectedItems, category]);
	};

	const removeItem = (id) => {
		onChange(selectedItems.filter((item) => Number(item.id) !== Number(id)));
	};

	const moveItem = (index, direction) => {
		const next = [...selectedItems];
		const target = index + direction;
		if (target < 0 || target >= next.length) return;
		[next[index], next[target]] = [next[target], next[index]];
		onChange(next);
	};

	return (
		<div className="space-y-4">
			<Field>
				<Label>Agregar categoría</Label>
				<Listbox
					value=""
					onChange={addCategory}
					placeholder={
						selectedItems.length >= maxItems
							? `Máximo ${maxItems} categorías`
							: "Seleccionar categoría"
					}
					disabled={selectedItems.length >= maxItems || available.length === 0}
				>
					{available.map((category) => (
						<ListboxOption
							key={category.id}
							value={String(category.id)}
						>
							<ListboxLabel>{category.name}</ListboxLabel>
						</ListboxOption>
					))}
				</Listbox>
				{error && <ErrorMessage>{error}</ErrorMessage>}
			</Field>

			{selectedItems.length === 0 ? (
				<Text className="text-sm text-zinc-500">
					Ninguna categoría seleccionada.
				</Text>
			) : (
				<ul className="space-y-2">
					{selectedItems.map((item, index) => (
						<li
							key={item.id}
							className="flex items-center justify-between gap-3 rounded-lg border border-zinc-200 px-3 py-2 dark:border-zinc-700"
						>
							<span className="font-medium">{item.name}</span>
							<div className="flex items-center gap-1">
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
									disabled={index === selectedItems.length - 1}
									onClick={() => moveItem(index, 1)}
								>
									<ChevronDownIcon className="size-4" />
								</Button>
								<Button
									type="button"
									plain
									onClick={() => removeItem(item.id)}
								>
									<XMarkIcon className="size-4" />
								</Button>
							</div>
						</li>
					))}
				</ul>
			)}
		</div>
	);
}
