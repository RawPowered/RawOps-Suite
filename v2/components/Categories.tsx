import React from 'react';
import { CATEGORIES } from '../constants';
import type { Category } from '../types';

const CategoryCard: React.FC<{ category: Category }> = ({ category }) => (
  <a href={category.href} className="group block bg-white p-6 rounded-lg border border-gray-200 hover:shadow-xl hover:-translate-y-1 transition-all duration-300">
    <div className="flex items-center">
      <div className="flex-shrink-0 bg-blue-100 h-12 w-12 rounded-md flex items-center justify-center">
        <i className={`fas ${category.icon} text-blue-600 text-2xl`}></i>
      </div>
      <h3 className="ml-4 text-xl font-bold text-gray-900 group-hover:text-blue-600 transition-colors">{category.name}</h3>
    </div>
    <p className="mt-4 text-gray-600">{category.description}</p>
  </a>
);

const Categories: React.FC = () => {
  return (
    <section className="py-20 sm:py-24 bg-white">
      <div className="container mx-auto px-4 sm:px-6 lg:px-8">
        <div className="text-center">
          <h2 className="text-3xl sm:text-4xl font-bold tracking-tight text-gray-900">Shop by Category</h2>
          <p className="mt-4 max-w-2xl mx-auto text-lg text-gray-600">
            Find exactly what you need with our curated collections.
          </p>
        </div>
        <div className="mt-12 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-8">
          {CATEGORIES.map((category) => (
            <CategoryCard key={category.name} category={category} />
          ))}
        </div>
      </div>
    </section>
  );
};

export default Categories;
