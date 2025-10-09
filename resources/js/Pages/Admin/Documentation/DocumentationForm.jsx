import { Subheading } from "@/Components/Catalyst/heading";
import { useForm } from "@inertiajs/react";
import { Button } from "@/Components/Catalyst/button";
import { Text, TextLink } from "@/Components/Catalyst/text";
import {
	MDXEditor,
	UndoRedo,
	BoldItalicUnderlineToggles,
	BlockTypeSelect,
	InsertTable,
	toolbarPlugin,
	headingsPlugin,
	tablePlugin,
	linkPlugin,
	listsPlugin,
	CreateLink,
	ListsToggle,
	Separator,
} from "@mdxeditor/editor";
import "@mdxeditor/editor/style.css";
import {
	ArchiveBoxArrowDownIcon,
	ArrowTopRightOnSquareIcon,
} from "@heroicons/react/16/solid";

export default function DocumentationForm({ document, initialMarkdown }) {
	const { data, setData, patch, processing } = useForm({
		[document.field]: initialMarkdown ?? "",
	});

	const submit = (e) => {
		e.preventDefault();

		if (!processing) {
			patch(route("admin.documentation.update"));
		}
	};

	return (
		<form className="space-y-12" onSubmit={submit}>
			<div className="space-y-3">
				<div className="mb-2">
					<Subheading>{document.label}</Subheading>

					<Text>
						Aquí puedes editar el documento{" "}
						<TextLink href={route(document.previewRouteName)}>
							{document.label}.
							<ArrowTopRightOnSquareIcon className="inline-block size-5 align-middle" />
						</TextLink>
					</Text>
				</div>

				<div className="relative inset-0 isolate rounded-lg bg-gray-50">
					<MDXEditor
						contentEditableClassName="prose"
						markdown={data[document.field]}
						onChange={(markdown) =>
							setData(document.field, markdown)
						}
						plugins={[
							headingsPlugin(),
							linkPlugin(),
							listsPlugin(),
							tablePlugin(),
							toolbarPlugin({
								toolbarContents: () => (
									<>
										{" "}
										<UndoRedo />
										<Separator />
										<BlockTypeSelect />
										<BoldItalicUnderlineToggles />
										<Separator />
										<ListsToggle />
										<Separator />
										<CreateLink />
										<InsertTable />
									</>
								),
							}),
						]}
					/>
					<div className="absolute bottom-2 right-2 flex items-center gap-2">
						<Button type="submit">
							<ArchiveBoxArrowDownIcon />
							Guardar
						</Button>
					</div>
				</div>
			</div>
		</form>
	);
}
