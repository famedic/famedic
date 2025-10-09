import { Input, InputGroup } from "@/Components/Catalyst/input";
import { MagnifyingGlassIcon } from "@heroicons/react/16/solid";

export default function SearchInput({
	value,
	onChange,
	placeholder = "Buscar...",
}) {
	return (
		<div className="flex-1 md:max-w-md">
			<InputGroup>
				<MagnifyingGlassIcon />
				<Input
					placeholder={placeholder}
					value={value}
					onChange={(e) => onChange(e.target.value)}
				/>
			</InputGroup>
		</div>
	);
}