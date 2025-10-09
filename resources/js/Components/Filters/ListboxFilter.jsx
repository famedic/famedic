import { Field, Label } from "@/Components/Catalyst/fieldset";
import { Listbox } from "@/Components/Catalyst/listbox";

export default function ListboxFilter({
	label,
	placeholder,
	value,
	onChange,
	children,
}) {
	return (
		<Field>
			<Label>{label}</Label>
			<Listbox
				placeholder={placeholder}
				value={value}
				onChange={onChange}
			>
				{children}
			</Listbox>
		</Field>
	);
}
